<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;

/**
 * Puts OpenAI's Measurement Pixel on the storefront and tells it what the
 * shopper did.
 *
 * Page-level events (page, product, checkout, purchase) are printed with
 * the page. Add-to-cart usually happens without a page load, so it is
 * handed to the script in a short-lived cookie set on the add-to-cart
 * response, whichever way it came (classic form, AJAX button, block
 * button through the Store API, or a redirect to the cart). The cookie
 * keeps this out of the WooCommerce session, which also holds the cart:
 * rewriting the session from a second request could lose a cart line.
 *
 * Nothing that identifies a shopper is printed into a page a cache could
 * serve to someone else: per-shopper data appears only on the thank-you
 * page, which WooCommerce never caches.
 */
final class PixelFrontend
{
    public const SDK_URL = 'https://bzrcdn.openai.com/sdk/oaiq.min.js';
    public const HANDLE = 'faxc-pixel';
    public const META_FIRED = '_faxc_pixel_fired';
    public const PENDING_COOKIE = 'faxc_pe';

    private const PENDING_LIMIT = 5;
    /** Browsers cap a cookie near 4 KB; stay well under it. */
    private const PENDING_BYTES = 3000;
    private const PENDING_NAME_LIMIT = 80;

    public function __construct(
        private readonly MeasurementSettings $settings,
        private readonly EventData $data,
    ) {
    }

    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue'], 20);
        add_action('woocommerce_add_to_cart', [$this, 'remember_add_to_cart'], 10, 6);
    }

    public function enqueue(): void
    {
        if (! $this->settings->is_enabled() || is_admin()) {
            return;
        }

        /**
         * Skip the pixel on this request, e.g. for staff accounts.
         *
         * @param bool $enabled
         */
        if (! apply_filters('feedagentx_pixel_enabled_for_request', true)) {
            return;
        }

        $config = [
            'pixelId' => $this->settings->get_pixel_id(),
            'sdk' => self::SDK_URL,
            'consentMode' => $this->settings->consent_mode(),
            // A WP Consent API banner decides. Its script loads after this
            // one, so the page says so up front.
            'cmp' => Consent::cmp_type_known(),
            'cmpWaitsForType' => Consent::cmp_type_in_browser(),
            'debug' => $this->settings->is_debug(),
            'events' => [],
            'pendingCookie' => self::PENDING_COOKIE,
            'cookiePath' => self::cookie_path(),
        ];

        $config['events'][] = ['page_viewed', ['type' => 'contents']];

        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $this->add_purchase($config);
        } elseif (function_exists('is_product') && is_product()) {
            $this->add_product_view($config);
        } elseif (
            function_exists('is_checkout') && is_checkout()
            && ! (function_exists('is_checkout_pay_page') && is_checkout_pay_page())
        ) {
            $this->add_checkout($config);
        }

        wp_register_script(self::HANDLE, plugins_url('assets/js/pixel.js', FAXC_PLUGIN_FILE), [], FAXC_VERSION, false);
        wp_add_inline_script(self::HANDLE, 'window.faxcPixel = ' . wp_json_encode($config) . ';', 'before');
        wp_enqueue_script(self::HANDLE);
    }

    // ───────────────────────── Page events ─────────────────────────

    private function add_product_view(array &$config): void
    {
        // A classic add-to-cart posts back to the product page; that
        // reload is not a new view.
        if (! empty($_REQUEST['add-to-cart'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check
            return;
        }
        $product = wc_get_product(get_queried_object_id());
        if ($product instanceof \WC_Product) {
            $config['events'][] = ['contents_viewed', $this->data->product_viewed($product)];
        }
    }

    private function add_checkout(array &$config): void
    {
        $cart = function_exists('WC') ? WC()->cart : null;
        if ($cart instanceof \WC_Cart && ! $cart->is_empty()) {
            $config['events'][] = ['checkout_started', $this->data->cart($cart)];
        }
    }

    private function add_purchase(array &$config): void
    {
        $order = $this->received_order();
        if ($order === null) {
            return;
        }

        if ($this->settings->sends_customer_data()) {
            $user = $this->pixel_user($order);
            if ($user !== []) {
                $config['user'] = $user;
            }
        }

        /**
         * Order statuses that count as a purchase on the thank-you page.
         * Pending and failed payments are left to the server event, which
         * fires once the payment completes.
         *
         * @param list<string> $statuses
         */
        $statuses = (array) apply_filters('feedagentx_pixel_browser_order_statuses', ['processing', 'completed', 'on-hold']);
        if (
            ! OrderAttribution::is_storefront_order($order)
            || ! in_array($order->get_status(), $statuses, true)
            || $order->get_meta(self::META_FIRED)
        ) {
            return; // A reload of the thank-you page is not a second purchase.
        }

        $config['events'][] = [
            'order_created',
            $this->data->order($order, false),
            ['event_id' => $this->data->order_event_id($order)],
        ];

        $order->update_meta_data(self::META_FIRED, (string) time());
        $order->save_meta_data();
    }

    /** The order on the thank-you page, only with its matching order key. */
    private function received_order(): ?\WC_Order
    {
        $order_id = absint(get_query_var('order-received'));
        $key = isset($_GET['key']) ? sanitize_text_field(wp_unslash((string) $_GET['key'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the credential
        if (! $order_id || $key === '') {
            return null;
        }
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || ! hash_equals((string) $order->get_order_key(), $key)) {
            return null;
        }
        return $order;
    }

    /** Hashed in PHP; the pixel receives no raw personal data. */
    private function pixel_user(\WC_Order $order): array
    {
        $country = strtoupper((string) $order->get_billing_country());
        $calling_code = ($country !== '' && WC()->countries) ? (string) WC()->countries->get_country_calling_code($country) : '';

        $user = array_filter([
            'email_sha256' => UserDataHasher::email((string) $order->get_billing_email()),
            'phone_number_sha256' => UserDataHasher::phone((string) $order->get_billing_phone(), $calling_code),
            'external_id_sha256' => $order->get_customer_id() ? UserDataHasher::external_id((string) $order->get_customer_id()) : null,
            'first_name_sha256' => UserDataHasher::name((string) $order->get_billing_first_name()),
            'last_name_sha256' => UserDataHasher::name((string) $order->get_billing_last_name()),
        ]);

        if (preg_match('/^[A-Z]{2}$/', $country)) {
            $user['country'] = $country;
        }
        $city = trim((string) $order->get_billing_city());
        if ($city !== '') {
            $user['city'] = mb_substr($city, 0, 128);
        }
        $state = trim((string) $order->get_billing_state());
        if ($state !== '') {
            $states = WC()->countries ? WC()->countries->get_states($country) : [];
            $user['region'] = mb_substr(
                is_array($states) && isset($states[$state]) ? html_entity_decode(wp_strip_all_tags((string) $states[$state])) : $state,
                0,
                128
            );
        }
        $postcode = trim((string) $order->get_billing_postcode());
        if ($postcode !== '' && preg_match('/^[A-Za-z0-9 -]{1,32}$/', $postcode)) {
            $user['postal_code'] = $postcode;
        }
        return $user;
    }

    // ───────────────────────── Add to cart ─────────────────────────

    public function remember_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = [], $cart_item_data = []): void
    {
        if (! $this->settings->is_enabled() || (is_admin() && ! wp_doing_ajax()) || headers_sent()) {
            return;
        }
        $product = wc_get_product((int) ($variation_id ?: $product_id));
        if (! $product instanceof \WC_Product) {
            return;
        }

        $data = $this->data->item_added($product, (int) $quantity);
        foreach ($data['contents'] ?? [] as $i => $item) {
            $data['contents'][$i]['name'] = mb_substr((string) $item['name'], 0, self::PENDING_NAME_LIMIT);
        }

        $pending = self::decode_pending(isset($_COOKIE[self::PENDING_COOKIE]) ? (string) wp_unslash($_COOKIE[self::PENDING_COOKIE]) : ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded and validated by decode_pending()
        $pending[] = ['items_added', $data];
        $value = self::encode_pending($pending);

        // A grouped product adds several items in one request: later adds
        // must see the earlier ones.
        $_COOKIE[self::PENDING_COOKIE] = $value;
        setcookie(self::PENDING_COOKIE, $value, [
            'expires' => time() + HOUR_IN_SECONDS,
            'path' => self::cookie_path(),
            'secure' => is_ssl(),
            'httponly' => false, // read by the storefront script, its only purpose
            'samesite' => 'Lax',
        ]);
    }

    /**
     * @param list<array> $events
     * @return string base64url JSON, oldest events dropped to fit
     */
    public static function encode_pending(array $events): string
    {
        $events = array_slice(array_values($events), -self::PENDING_LIMIT);
        do {
            $value = rtrim(strtr(base64_encode((string) wp_json_encode($events)), '+/', '-_'), '=');
            if (strlen($value) <= self::PENDING_BYTES || count($events) <= 1) {
                return $value;
            }
            array_shift($events);
        } while (true);
    }

    /**
     * The cookie comes back from the browser, so only well-formed
     * add-to-cart events survive.
     *
     * @return list<array{0: string, 1: array}>
     */
    public static function decode_pending(string $value): array
    {
        if ($value === '' || strlen($value) > self::PENDING_BYTES * 2 || ! preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            return [];
        }
        $decoded = json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true);
        if (! is_array($decoded)) {
            return [];
        }
        $events = [];
        foreach ($decoded as $event) {
            if (is_array($event) && ($event[0] ?? null) === 'items_added' && is_array($event[1] ?? null) && ($event[1]['type'] ?? null) === 'contents') {
                $events[] = ['items_added', $event[1]];
            }
        }
        return array_slice($events, -self::PENDING_LIMIT);
    }

    private static function cookie_path(): string
    {
        return defined('COOKIEPATH') && COOKIEPATH ? (string) COOKIEPATH : '/';
    }
}
