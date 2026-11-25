<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;

/**
 * Remembers, on the order, what the server-side purchase event needs and
 * can no longer read once the shopper has left: the consent decision and
 * OpenAI's attribution cookies.
 *
 * `__oppref` is the ad-click reference and `__obref` the browser
 * reference, both written by OpenAI's Pixel on this site. They exist only
 * if the Pixel ran with consent, and are stored only if consent holds at
 * checkout.
 */
final class OrderAttribution
{
    public const META_CONSENT = '_faxc_mc';
    public const META_OPPREF = '_faxc_oppref';
    public const META_OBREF = '_faxc_obref';

    private const COOKIES = [
        self::META_OPPREF => '__oppref',
        self::META_OBREF => '__obref',
    ];

    public function __construct(
        private readonly MeasurementSettings $settings,
        private readonly Consent $consent,
    ) {
    }

    public function register(): void
    {
        // Classic checkout: the order is saved right after this runs.
        add_action('woocommerce_checkout_create_order', [$this, 'capture'], 10, 1);
        // Block checkout (Store API).
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'capture_and_save'], 10, 1);
    }

    public function capture(mixed $order): void
    {
        if (! $order instanceof \WC_Order || ! $this->settings->is_enabled()) {
            return;
        }

        $granted = $this->consent->granted_for_current_request();
        $order->update_meta_data(self::META_CONSENT, $granted ? '1' : '0');

        foreach (self::COOKIES as $meta => $cookie) {
            $value = $granted ? self::read_cookie($cookie) : null;
            if ($value !== null) {
                $order->update_meta_data($meta, $value);
            } else {
                $order->delete_meta_data($meta);
            }
        }
    }

    /**
     * Placed by a shopper through the storefront checkout. Orders entered
     * in the admin (even when the customer pays them through a payment
     * link) and subscription renewals are not purchases driven by an ad.
     */
    public static function is_storefront_order(\WC_Order $order): bool
    {
        /**
         * Which order sources count as ad conversions.
         *
         * @param list<string> $sources
         */
        $sources = (array) apply_filters('feedagentx_pixel_order_sources', ['checkout', 'store-api']);
        return in_array((string) $order->get_created_via(), $sources, true);
    }

    public function capture_and_save(mixed $order): void
    {
        $this->capture($order);
        if ($order instanceof \WC_Order && $order->get_id()) {
            $order->save_meta_data();
        }
    }

    /**
     * OpenAI asks for these to be passed on unmodified, so they are
     * validated rather than sanitised: anything unexpected is dropped.
     */
    public static function read_cookie(string $name): ?string
    {
        if (! isset($_COOKIE[$name]) || ! is_string($_COOKIE[$name])) {
            return null;
        }
        $value = wp_unslash($_COOKIE[$name]); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated against the pattern below; OpenAI requires the value unmodified
        return preg_match('/^[\x21-\x7E]{1,1024}$/', $value) ? $value : null;
    }
}
