<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;

/**
 * Server-side view of the shopper's measurement consent.
 *
 * The browser decides for the Pixel; this decides, at checkout, whether
 * the order may be sent from the server later. The two follow the same
 * rules so a shopper who declined in the banner is not measured through
 * the back door.
 */
final class Consent
{
    /** Set by the storefront script: "1" granted, "0" declined. */
    public const COOKIE = 'faxc_mc';

    public function __construct(
        private readonly MeasurementSettings $settings,
    ) {
    }

    /** A cookie banner integrated with the WP Consent API is active. */
    public static function cmp_active(): bool
    {
        return self::cmp_type_known() || self::cmp_type_in_browser();
    }

    /** The banner told WordPress its consent type (opt-in, opt-out). */
    public static function cmp_type_known(): bool
    {
        return function_exists('wp_get_consent_type')
            && function_exists('wp_has_consent')
            && (bool) wp_get_consent_type();
    }

    /**
     * The banner decides its consent type in the browser (for example by
     * visitor region) and asks scripts to wait for it. WordPress cannot
     * answer for it, so the storefront script records the answer in
     * self::COOKIE.
     */
    public static function cmp_type_in_browser(): bool
    {
        return function_exists('wp_has_consent')
            && ! self::cmp_type_known()
            && (bool) apply_filters('wp_consent_api_waitfor_consent_hook', false); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reads the WP Consent API's own filter
    }

    public function granted_for_current_request(): bool
    {
        $granted = $this->decide();

        /**
         * Override the consent decision for this request, e.g. for a
         * cookie banner that does not use the WP Consent API.
         *
         * @param bool $granted
         */
        return (bool) apply_filters('feedagentx_pixel_has_consent', $granted);
    }

    private function decide(): bool
    {
        if (self::cmp_type_known()) {
            return (bool) wp_has_consent('marketing');
        }

        $cookie = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash((string) $_COOKIE[self::COOKIE])) : null;
        if (self::cmp_type_in_browser()) {
            // Nothing recorded yet means the banner has not answered.
            return $cookie === '1';
        }
        if ($cookie === '0') {
            return false;
        }
        if ($this->settings->consent_mode() === MeasurementSettings::CONSENT_REQUIRE) {
            return $cookie === '1';
        }
        return true;
    }
}
