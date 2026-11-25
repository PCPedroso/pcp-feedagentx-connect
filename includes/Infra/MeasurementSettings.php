<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Options for the OpenAI Measurement Pixel and Conversions API.
 *
 * Kept apart from SettingsRepository because none of this reaches
 * FeedAgentX: the pixel runs in the shopper's browser and server events
 * go from this site straight to OpenAI. It works without a FeedAgentX
 * account.
 */
final class MeasurementSettings
{
    public const KEY_ENABLED = 'faxc_pixel_enabled';
    public const KEY_PIXEL_ID = 'faxc_pixel_id';
    public const KEY_CAPI_ENABLED = 'faxc_capi_enabled';
    public const KEY_CAPI_KEY = 'faxc_capi_key';
    public const KEY_CONSENT_MODE = 'faxc_pixel_consent_mode';
    public const KEY_MATCHING = 'faxc_pixel_matching';
    public const KEY_DEBUG = 'faxc_pixel_debug';
    public const KEY_ACTIVITY = 'faxc_pixel_activity';
    public const KEY_SITE_KEY = 'faxc_pixel_site_key';

    /** Follow a WP Consent API banner when there is one; measure otherwise. */
    public const CONSENT_AUTO = 'auto';
    /** Measure nobody until marketing consent is granted. */
    public const CONSENT_REQUIRE = 'require';

    private const RECENT_LIMIT = 20;

    /**
     * Switches are stored as "1"/"0", never as booleans: WordPress will
     * not store false for an option that does not exist yet (it equals
     * get_option()'s default), so a switch that defaults to on could
     * never be turned off on a fresh install.
     */
    private static function flag(string $key, bool $default): bool
    {
        $value = get_option($key, $default ? '1' : '0');
        return $value === true || $value === 1 || $value === '1';
    }

    public function is_enabled(): bool
    {
        return $this->is_switched_on() && $this->get_pixel_id() !== '';
    }

    public function is_switched_on(): bool
    {
        return self::flag(self::KEY_ENABLED, false);
    }

    public function get_pixel_id(): string
    {
        $value = (string) get_option(self::KEY_PIXEL_ID, '');
        return self::is_valid_pixel_id($value) ? $value : '';
    }

    public static function is_valid_pixel_id(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{4,64}$/', $value);
    }

    public function is_capi_switched_on(): bool
    {
        return self::flag(self::KEY_CAPI_ENABLED, false);
    }

    /** Server purchases can be sent: pixel on, CAPI on, key saved. */
    public function is_capi_ready(): bool
    {
        return $this->is_enabled() && $this->is_capi_switched_on() && $this->get_capi_key() !== '';
    }

    public function get_capi_key(): string
    {
        return trim((string) get_option(self::KEY_CAPI_KEY, ''));
    }

    /** The last four characters, for "saved" hints. Never the key itself. */
    public function capi_key_hint(): string
    {
        $key = $this->get_capi_key();
        return $key === '' ? '' : substr($key, -4);
    }

    public function consent_mode(): string
    {
        $mode = (string) get_option(self::KEY_CONSENT_MODE, self::CONSENT_AUTO);
        return $mode === self::CONSENT_REQUIRE ? self::CONSENT_REQUIRE : self::CONSENT_AUTO;
    }

    /** Hashed customer details improve matching; on unless turned off. */
    public function sends_customer_data(): bool
    {
        return self::flag(self::KEY_MATCHING, true);
    }

    public function is_debug(): bool
    {
        return self::flag(self::KEY_DEBUG, false);
    }

    /**
     * A random value fixed for this site, part of every purchase event
     * ID. Unlike home_url() it does not change with the language path,
     * the scheme or the request, so the thank-you page and the
     * background job always agree on the ID.
     */
    public function site_key(): string
    {
        $key = (string) get_option(self::KEY_SITE_KEY, '');
        if (preg_match('/^[a-z0-9]{8}$/', $key)) {
            return $key;
        }
        $key = strtolower(wp_generate_password(8, false, false));
        add_option(self::KEY_SITE_KEY, $key, '', false);
        return (string) get_option(self::KEY_SITE_KEY, $key);
    }

    /**
     * @param array{enabled: bool, pixel_id: string, capi_enabled: bool, capi_key: ?string, consent_mode: string, matching: bool, debug: bool} $values
     *        capi_key null keeps the saved key; '' removes it.
     */
    public function save(array $values): void
    {
        update_option(self::KEY_ENABLED, $values['enabled'] ? '1' : '0', false);
        update_option(self::KEY_PIXEL_ID, self::is_valid_pixel_id($values['pixel_id']) ? $values['pixel_id'] : '', false);
        update_option(self::KEY_CAPI_ENABLED, $values['capi_enabled'] ? '1' : '0', false);
        if ($values['capi_key'] !== null) {
            update_option(self::KEY_CAPI_KEY, trim($values['capi_key']), false);
        }
        update_option(
            self::KEY_CONSENT_MODE,
            $values['consent_mode'] === self::CONSENT_REQUIRE ? self::CONSENT_REQUIRE : self::CONSENT_AUTO,
            false
        );
        update_option(self::KEY_MATCHING, $values['matching'] ? '1' : '0', false);
        update_option(self::KEY_DEBUG, $values['debug'] ? '1' : '0', false);
        // Settled before the first storefront event can need it.
        $this->site_key();
    }

    // ───────────────────────── Activity ─────────────────────────

    /**
     * Server-side delivery history shown on the settings screen. Holds
     * order numbers and outcomes only: no customer details, no payloads.
     *
     * @return array{sent: int, failed: int, last_sent_at: ?int, last_error: ?array{at: int, message: string}, recent: list<array{order: int, status: string, at: int, message: string}>, test: ?array{at: int, ok: bool, message: string}}
     */
    public function activity(): array
    {
        $raw = get_option(self::KEY_ACTIVITY, []);
        $raw = is_array($raw) ? $raw : [];
        return [
            'sent' => (int) ($raw['sent'] ?? 0),
            'failed' => (int) ($raw['failed'] ?? 0),
            'last_sent_at' => isset($raw['last_sent_at']) ? (int) $raw['last_sent_at'] : null,
            'last_error' => is_array($raw['last_error'] ?? null) ? $raw['last_error'] : null,
            'recent' => is_array($raw['recent'] ?? null) ? array_values($raw['recent']) : [],
            'test' => is_array($raw['test'] ?? null) ? $raw['test'] : null,
        ];
    }

    /** @param string $status sent | retrying | failed | skipped */
    public function record_delivery(int $order_id, string $status, string $message = ''): void
    {
        $activity = $this->activity();
        $now = time();

        if ($status === 'sent') {
            $activity['sent']++;
            $activity['last_sent_at'] = $now;
        } elseif ($status === 'failed') {
            $activity['failed']++;
            $activity['last_error'] = ['at' => $now, 'message' => $message];
        }

        array_unshift($activity['recent'], [
            'order' => $order_id,
            'status' => $status,
            'at' => $now,
            'message' => $message,
        ]);
        $activity['recent'] = array_slice($activity['recent'], 0, self::RECENT_LIMIT);

        update_option(self::KEY_ACTIVITY, $activity, false);
    }

    public function record_test(bool $ok, string $message): void
    {
        $activity = $this->activity();
        $activity['test'] = ['at' => time(), 'ok' => $ok, 'message' => $message];
        update_option(self::KEY_ACTIVITY, $activity, false);
    }

    /** @return list<string> */
    public static function option_keys(): array
    {
        return [
            self::KEY_ENABLED,
            self::KEY_PIXEL_ID,
            self::KEY_CAPI_ENABLED,
            self::KEY_CAPI_KEY,
            self::KEY_CONSENT_MODE,
            self::KEY_MATCHING,
            self::KEY_DEBUG,
            self::KEY_ACTIVITY,
            self::KEY_SITE_KEY,
        ];
    }
}
