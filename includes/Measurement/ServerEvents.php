<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\MeasurementSettings;

/**
 * Sends each paid order to OpenAI's Conversions API from this server.
 *
 * The browser Pixel can miss a purchase (ad blockers, a closed tab, a
 * payment page that never returns). This does not depend on the shopper
 * reaching the thank-you page. Both sides use the same event ID, so a
 * purchase seen by both is counted once.
 *
 * Delivery runs in the background through Action Scheduler and is retried
 * when OpenAI is unreachable. The event is built at send time from the
 * order, so no customer details sit in the scheduled-actions table.
 */
final class ServerEvents
{
    public const ACTION = 'faxc_capi_send_order';
    public const REQUEUE_ACTION = 'faxc_capi_requeue';
    public const GROUP = 'feedagentx';

    public const META_STATE = '_faxc_capi_state';
    public const META_STATE_AT = '_faxc_capi_state_at';

    /** OpenAI rejects events older than seven days. */
    private const MAX_AGE = 7 * DAY_IN_SECONDS;
    private const MAX_ATTEMPTS = 5;
    /** Wait before attempt 2, 3, 4 and 5, in seconds. */
    private const BACKOFF = [60, 300, 1800, 7200];

    public function __construct(
        private readonly MeasurementSettings $settings,
        private readonly ConversionsApi $api,
        private readonly EventData $data,
    ) {
    }

    public function register(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'maybe_queue']);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_queue']);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_queue']);
        add_action(self::ACTION, [$this, 'deliver'], 10, 2);
        add_action(self::REQUEUE_ACTION, [$this, 'requeue_stranded']);
    }

    /**
     * Deactivating the plugin removes queued sends. On reactivation, this
     * puts back every order still marked queued that OpenAI would accept.
     */
    public function requeue_stranded(): void
    {
        if (! $this->settings->is_capi_ready() || ! function_exists('wc_get_orders')) {
            return;
        }
        $ids = wc_get_orders([
            'limit' => 500,
            'return' => 'ids',
            'date_paid' => '>' . (time() - self::MAX_AGE),
            'meta_query' => [['key' => self::META_STATE, 'value' => 'queued']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- runs once, on activation
        ]);
        foreach ((array) $ids as $order_id) {
            $this->schedule((int) $order_id, 1, 0);
        }
    }

    public function maybe_queue(mixed $order_id): void
    {
        if (! $this->settings->is_capi_ready()) {
            return;
        }
        $order = wc_get_order((int) $order_id);
        if (! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund) {
            return;
        }
        if ((string) $order->get_meta(self::META_STATE) !== '') {
            return; // Already queued, sent or deliberately skipped.
        }

        $skip = $this->skip_reason($order);
        if ($skip !== null) {
            $this->set_state($order, 'skipped');
            $this->settings->record_delivery($order->get_id(), 'skipped', $skip);
            return;
        }

        $this->set_state($order, 'queued');
        $this->schedule($order->get_id(), 1, 0);
    }

    /** Action Scheduler callback. */
    public function deliver(mixed $order_id, mixed $attempt = 1): void
    {
        $order_id = (int) $order_id;
        $attempt = max(1, (int) $attempt);
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || (string) $order->get_meta(self::META_STATE) === 'sent') {
            return;
        }
        if (! $this->settings->is_capi_ready()) {
            $this->fail($order, __('Conversions API was turned off before the order was sent.', 'feedagentx-connect'));
            return;
        }
        if ($this->paid_at($order) < time() - self::MAX_AGE) {
            $this->fail($order, __('The order is more than seven days old; OpenAI no longer accepts it.', 'feedagentx-connect'));
            return;
        }

        $result = $this->api->send([$this->build_event($order)]);

        if ($result['ok']) {
            $this->set_state($order, 'sent');
            $this->settings->record_delivery($order_id, 'sent');
            return;
        }

        if ($result['retryable'] && $attempt < self::MAX_ATTEMPTS) {
            $delay = self::BACKOFF[$attempt - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1];
            $this->schedule($order_id, $attempt + 1, $delay);
            $this->settings->record_delivery(
                $order_id,
                'retrying',
                /* translators: 1: error message, 2: attempt number */
                sprintf(__('%1$s (attempt %2$d, retrying)', 'feedagentx-connect'), $result['message'], $attempt)
            );
            return;
        }

        $this->fail($order, $result['message']);
    }

    /** The Conversions API event for an order. Public for tests and the admin test button. */
    public function build_event(\WC_Order $order): array
    {
        $event = [
            'id' => $this->data->order_event_id($order),
            'type' => 'order_created',
            'timestamp_ms' => $this->paid_at($order) * 1000,
            'action_source' => 'web',
            // The order-received URL carries the order key; the checkout page does not.
            'source_url' => (string) wc_get_checkout_url(),
            'data' => $this->data->order($order, true),
        ];

        $oppref = (string) $order->get_meta(OrderAttribution::META_OPPREF);
        if ($oppref !== '') {
            $event['oppref'] = $oppref;
        }

        $user = $this->user($order);
        if ($user !== []) {
            $event['user'] = $user;
        }

        return $event;
    }

    /** Why an order is not sent, or null to send it. */
    private function skip_reason(\WC_Order $order): ?string
    {
        if (! OrderAttribution::is_storefront_order($order)) {
            return __('Not placed through the storefront checkout.', 'feedagentx-connect');
        }

        $consent = (string) $order->get_meta(OrderAttribution::META_CONSENT);
        if ($consent === '') {
            return __('Placed before the pixel was turned on.', 'feedagentx-connect');
        }
        if ($consent !== '1') {
            return __('The customer did not consent to marketing measurement.', 'feedagentx-connect');
        }

        if ($this->paid_at($order) < time() - self::MAX_AGE) {
            return __('The order is more than seven days old; OpenAI no longer accepts it.', 'feedagentx-connect');
        }
        return null;
    }

    private function user(\WC_Order $order): array
    {
        $user = [];

        $obref = (string) $order->get_meta(OrderAttribution::META_OBREF);
        if ($obref !== '') {
            $user['obref'] = $obref;
        }

        if ($this->settings->sends_customer_data()) {
            $country = strtoupper((string) $order->get_billing_country());
            $calling_code = '';
            if ($country !== '' && function_exists('WC') && WC()->countries) {
                $calling_code = (string) WC()->countries->get_country_calling_code($country);
            }

            $hashes = [
                'emails_sha256' => UserDataHasher::email((string) $order->get_billing_email()),
                'phone_numbers_sha256' => UserDataHasher::phone((string) $order->get_billing_phone(), $calling_code),
                'first_names_sha256' => UserDataHasher::name((string) $order->get_billing_first_name()),
                'last_names_sha256' => UserDataHasher::name((string) $order->get_billing_last_name()),
                'external_ids_sha256' => $order->get_customer_id() ? UserDataHasher::external_id((string) $order->get_customer_id()) : null,
            ];
            foreach ($hashes as $field => $hash) {
                if ($hash !== null) {
                    $user[$field] = [$hash];
                }
            }

            $city = trim((string) $order->get_billing_city());
            $postcode = trim((string) $order->get_billing_postcode());
            $region = $this->region_name($country, (string) $order->get_billing_state());
            if (preg_match('/^[A-Z]{2}$/', $country)) {
                $user['countries'] = [$country];
            }
            if ($city !== '') {
                $user['cities'] = [$city];
            }
            if ($region !== '') {
                $user['regions'] = [$region];
            }
            if ($postcode !== '' && preg_match('/^[A-Za-z0-9 -]{1,32}$/', $postcode)) {
                $user['postal_codes'] = [$postcode];
            }
        }

        $ip = (string) $order->get_customer_ip_address();
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $user['ip_address'] = $ip;
        }
        $agent = trim((string) $order->get_customer_user_agent());
        if ($agent !== '') {
            $user['user_agent'] = $agent;
        }

        return $user;
    }

    /** "CA" → "California"; falls back to the stored value. */
    private function region_name(string $country, string $state): string
    {
        $state = trim($state);
        if ($state === '' || $country === '' || ! function_exists('WC') || ! WC()->countries) {
            return $state;
        }
        $states = WC()->countries->get_states($country);
        return is_array($states) && isset($states[$state]) ? html_entity_decode(wp_strip_all_tags((string) $states[$state])) : $state;
    }

    private function paid_at(\WC_Order $order): int
    {
        $date = $order->get_date_paid() ?: $order->get_date_created();
        return $date ? $date->getTimestamp() : time();
    }

    private function schedule(int $order_id, int $attempt, int $delay): void
    {
        $args = [$order_id, $attempt];
        if ($delay === 0 && function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION, $args, self::GROUP);
            return;
        }
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $delay, self::ACTION, $args, self::GROUP);
            return;
        }
        wp_schedule_single_event(time() + $delay, self::ACTION, $args);
    }

    private function fail(\WC_Order $order, string $message): void
    {
        $this->set_state($order, 'failed');
        $this->settings->record_delivery($order->get_id(), 'failed', $message);
        Logger::warning('Conversions API purchase not delivered', [
            'order_id' => $order->get_id(),
            'reason' => $message,
        ]);
    }

    private function set_state(\WC_Order $order, string $state): void
    {
        $order->update_meta_data(self::META_STATE, $state);
        $order->update_meta_data(self::META_STATE_AT, (string) time());
        $order->save_meta_data();
    }
}
