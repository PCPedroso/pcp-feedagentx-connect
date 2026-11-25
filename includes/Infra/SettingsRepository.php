<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Type-safe wrapper for plugin options.
 *
 * Single source of truth for option keys, defaults, and reading/writing.
 * Anything outside Infra/ should go through this — never call get_option directly.
 */
final class SettingsRepository
{
    public const KEY_READ_TOKEN = 'faxc_read_token';
    public const KEY_TOKEN_GENERATED_AT = 'faxc_read_token_generated_at';
    public const KEY_SCHEMA_VERSION = 'faxc_schema_version';
    public const KEY_TARGET_COUNTRY = 'faxc_target_country';

    // Eligibility defaults for the OpenAI flat (file-upload) spec. Global
    // values here; overridable per product via _faxc_eligible_search /
    // _faxc_eligible_checkout post meta ('' inherit, '0'/'1' override).
    public const KEY_ENABLE_SEARCH = 'faxc_enable_search';
    public const KEY_ENABLE_CHECKOUT = 'faxc_enable_checkout';
    public const KEY_ENABLE_ADS = 'faxc_enable_ads';
    public const KEY_ADS_METADATA = 'faxc_ads_metadata';
    public const KEY_TARGET_COUNTRIES = 'faxc_target_countries';
    public const KEY_RETURN_POLICY_URL = 'faxc_return_policy_url';
    public const KEY_RETURN_DEADLINE_DAYS = 'faxc_return_deadline_days';
    public const KEY_BRAND_OVERRIDE = 'faxc_brand_override';
    public const KEY_BRAND_SOURCE = 'faxc_brand_source';
    public const KEY_EXCLUDED_CATEGORIES = 'faxc_excluded_categories';
    public const KEY_SETTINGS_OWNER = 'faxc_settings_owner';
    public const KEY_SETTINGS_OWNER_CLAIM = 'faxc_settings_owner_claim';
    public const KEY_RATE_LIMIT_PER_MINUTE = 'faxc_rate_limit_per_minute';
    public const KEY_UNAUTH_RATE_LIMIT_PER_MINUTE = 'faxc_unauth_rate_limit_per_minute';
    public const KEY_LOG_RETENTION_DAYS = 'faxc_log_retention_days';
    public const KEY_LOG_LEVEL = 'faxc_log_level';
    public const KEY_LOG_ENABLED = 'faxc_log_enabled';
    public const KEY_CHANGE_LOG_RETENTION_DAYS = 'faxc_change_log_retention_days';
    public const KEY_INCLUDE_PRIVATE_PRODUCTS = 'faxc_include_private_products';
    public const KEY_DEFAULT_CONDITION = 'faxc_default_condition';
    public const KEY_LAST_APP_CONTACT = 'faxc_last_app_contact';
    public const KEY_INSTALLED_AT = 'faxc_installed_at';

    /** A store FeedAgentX has read within this window counts as connected. */
    private const CONNECTED_WINDOW = 7 * DAY_IN_SECONDS;

    public const BRAND_SOURCE_AUTO = 'auto';
    public const BRAND_SOURCE_OVERRIDE = 'override';
    public const BRAND_SOURCE_META = 'meta';
    public const BRAND_SOURCE_ATTRIBUTE = 'attribute';
    public const BRAND_SOURCE_TAXONOMY = 'taxonomy';
    public const BRAND_SOURCE_SITE_NAME = 'site_name';

    public function get_read_token(): string
    {
        return (string) get_option(self::KEY_READ_TOKEN, '');
    }

    public function set_read_token(string $token): void
    {
        update_option(self::KEY_READ_TOKEN, $token, false);
        update_option(self::KEY_TOKEN_GENERATED_AT, current_time('mysql', 1), false);
    }

    public function get_token_generated_at(): ?string
    {
        $value = get_option(self::KEY_TOKEN_GENERATED_AT, '');
        return $value !== '' ? (string) $value : null;
    }

    public function get_schema_version(): string
    {
        return (string) get_option(self::KEY_SCHEMA_VERSION, '0.0.0');
    }

    public function set_schema_version(string $version): void
    {
        update_option(self::KEY_SCHEMA_VERSION, $version, false);
    }

    public function get_target_country(): ?string
    {
        $value = (string) get_option(self::KEY_TARGET_COUNTRY, '');
        return $value !== '' ? strtoupper($value) : null;
    }

    public function set_target_country(?string $country): void
    {
        update_option(self::KEY_TARGET_COUNTRY, $country !== null ? strtoupper($country) : '', false);
    }

    public function is_search_enabled(): bool
    {
        return (bool) get_option(self::KEY_ENABLE_SEARCH, true);
    }

    public function is_checkout_enabled(): bool
    {
        return (bool) get_option(self::KEY_ENABLE_CHECKOUT, false);
    }

    public function is_ads_enabled(): bool
    {
        return (bool) get_option(self::KEY_ENABLE_ADS, false);
    }

    /**
     * Optional `ads_metadata` object (flat spec: string keys → string
     * values, e.g. {"bidding_tier":"high"}). Stored as an assoc array;
     * non-string members are dropped defensively.
     *
     * @return array<string, string>
     */
    public function get_ads_metadata(): array
    {
        $raw = get_option(self::KEY_ADS_METADATA, []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $key => $value) {
            if (is_string($key) && $key !== '' && is_scalar($value) && (string) $value !== '') {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }

    /**
     * Store base country (ISO 3166-1 alpha-2). Derived, not a setting —
     * WooCommerce is the source of truth; the raw option is the fallback
     * when WC() isn't booted (subdivision suffix like ":CA" stripped).
     */
    public function get_store_country(): string
    {
        if (function_exists('WC')) {
            try {
                $country = strtoupper((string) \WC()->countries->get_base_country());
                if (preg_match('/^[A-Z]{2}$/', $country)) {
                    return $country;
                }
            } catch (\Throwable) {
                // Fall through to the raw option.
            }
        }

        $raw = strtoupper((string) get_option('woocommerce_default_country', ''));
        $country = substr($raw, 0, 2);
        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'US';
    }

    /**
     * @return string[] Raw stored list — may be empty; UI treats empty as
     *                  "use store country".
     */
    public function get_target_countries_setting(): array
    {
        $raw = get_option(self::KEY_TARGET_COUNTRIES, []);
        if (! is_array($raw)) {
            return [];
        }
        $codes = [];
        foreach ($raw as $code) {
            $code = strtoupper(trim((string) $code));
            if (preg_match('/^[A-Z]{2}$/', $code)) {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * @return string[] Effective target countries; falls back to [store_country]
     *                  when the setting is empty.
     */
    public function get_target_countries(): array
    {
        $codes = $this->get_target_countries_setting();
        return ! empty($codes) ? $codes : [$this->get_store_country()];
    }

    public function get_return_policy_url(): ?string
    {
        $value = (string) get_option(self::KEY_RETURN_POLICY_URL, '');
        return $value !== '' ? $value : null;
    }

    public function accepts_returns(): ?bool
    {
        $raw = get_option('faxc_accepts_returns', '');
        return in_array((string) $raw, ['0', '1'], true) ? (string) $raw === '1' : null;
    }

    public function get_return_deadline_days(): int
    {
        return (int) get_option(self::KEY_RETURN_DEADLINE_DAYS, 14);
    }

    public function get_brand_override(): ?string
    {
        $value = (string) get_option(self::KEY_BRAND_OVERRIDE, '');
        return $value !== '' ? $value : null;
    }

    public function get_brand_source(): string
    {
        $value = (string) get_option(self::KEY_BRAND_SOURCE, self::BRAND_SOURCE_AUTO);
        return $value !== '' ? $value : self::BRAND_SOURCE_AUTO;
    }


    public function get_rate_limit_per_minute(): int
    {
        $value = (int) get_option(self::KEY_RATE_LIMIT_PER_MINUTE, 120);
        return $value > 0 ? $value : 120;
    }

    public function get_unauth_rate_limit_per_minute(): int
    {
        $value = (int) get_option(self::KEY_UNAUTH_RATE_LIMIT_PER_MINUTE, 10);
        // 0 disables the IP throttle; negative is treated as the default.
        return $value >= 0 ? $value : 10;
    }

    public function get_log_retention_days(): int
    {
        $value = (int) get_option(self::KEY_LOG_RETENTION_DAYS, 14);
        return $value > 0 ? $value : 14;
    }

    public function get_change_log_retention_days(): int
    {
        $value = (int) get_option(self::KEY_CHANGE_LOG_RETENTION_DAYS, 30);
        return $value > 0 ? $value : 30;
    }


    public function get_default_condition(): string
    {
        $value = (string) get_option(self::KEY_DEFAULT_CONDITION, 'new');
        return $value !== '' ? $value : 'new';
    }

    /**
     * FeedAgentX just read the catalog with a valid token. Written at most
     * once an hour, so a sync does not turn every request into a write.
     */
    public function record_app_contact(): void
    {
        $now = time();
        if ($now - (int) get_option(self::KEY_LAST_APP_CONTACT, 0) >= HOUR_IN_SECONDS) {
            update_option(self::KEY_LAST_APP_CONTACT, $now, false);
        }
    }

    public function last_app_contact(): ?int
    {
        $at = (int) get_option(self::KEY_LAST_APP_CONTACT, 0);
        return $at > 0 ? $at : null;
    }

    /** FeedAgentX has read this store recently: it is connected and in use. */
    public function is_connected_to_app(): bool
    {
        $at = $this->last_app_contact();
        return $at !== null && time() - $at < self::CONNECTED_WINDOW;
    }

    /**
     * When this site first ran a version that records it: activation for
     * new installs, the update to 2.5.0 for older ones. Written once and
     * never moved, so "a week of use" can be measured.
     */
    public function installed_at(): int
    {
        $at = (int) get_option(self::KEY_INSTALLED_AT, 0);
        if ($at <= 0) {
            $at = time();
            add_option(self::KEY_INSTALLED_AT, $at, '', false);
        }
        return $at;
    }

    public function update(string $key, mixed $value): void
    {
        update_option($key, $value, false);
    }
}
