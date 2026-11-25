<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Schema-version migration coordinator.
 *
 * On plugin load (and activation), compares the stored schema version against
 * the bundled version and runs any pending migrations in order. Each migration
 * is idempotent so partial failure is recoverable.
 */
final class Migrations
{
    /**
     * Migration map: version => closure(): void
     * Newest version at the bottom.
     *
     * @var array<string, callable>
     */
    private array $migrations;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
        $this->migrations = [
            '1.0.0' => fn () => $this->migration_1_0_0(),
            '2.0.0' => fn () => $this->migration_2_0_0(),
            '2.1.0' => fn () => $this->migration_2_1_0(),
            '2.4.0' => fn () => $this->migration_2_4_0(),
        ];
    }

    public function run(string $current_plugin_version): void
    {
        $stored = $this->settings->get_schema_version();

        foreach ($this->migrations as $version => $migration) {
            if (version_compare($version, $stored, '>') && version_compare($version, $current_plugin_version, '<=')) {
                try {
                    $migration();
                    $this->settings->set_schema_version($version);
                    Logger::info('Migration applied', ['version' => $version]);
                } catch (\Throwable $e) {
                    Logger::error('Migration failed', [
                        'version' => $version,
                        'error' => $e->getMessage(),
                    ]);
                    return;
                }
            }
        }

        if (version_compare($current_plugin_version, $stored, '>')) {
            $this->settings->set_schema_version($current_plugin_version);
            // First load of a new install or of an update: start the clock
            // the review request waits on, if it is not running yet.
            $this->settings->installed_at();
        }
    }

    /**
     * 1.0.0 — initial change_log table (matches the original Installer schema).
     */
    private function migration_1_0_0(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'faxc_change_log';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            change_type VARCHAR(20) NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY product_id_idx (product_id),
            KEY updated_at_idx (updated_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * 2.0.0 — Upcoming-schema rewrite. Adds default options for the new
     * settings that didn't exist in v1.
     */
    private function migration_2_0_0(): void
    {
        $defaults = [
            SettingsRepository::KEY_TARGET_COUNTRY => $this->detect_target_country(),
            SettingsRepository::KEY_RETURN_DEADLINE_DAYS => 14,
            SettingsRepository::KEY_BRAND_SOURCE => SettingsRepository::BRAND_SOURCE_AUTO,
            SettingsRepository::KEY_RATE_LIMIT_PER_MINUTE => 120,
            SettingsRepository::KEY_UNAUTH_RATE_LIMIT_PER_MINUTE => 10,
            SettingsRepository::KEY_LOG_RETENTION_DAYS => 14,
            SettingsRepository::KEY_LOG_LEVEL => Logger::INFO,
            SettingsRepository::KEY_LOG_ENABLED => true,
            SettingsRepository::KEY_CHANGE_LOG_RETENTION_DAYS => 30,
            SettingsRepository::KEY_DEFAULT_CONDITION => 'new',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value, '', false);
            }
        }
    }

    /**
     * 2.1.0 — OpenAI flat (file-upload) spec. Eligibility flags return as real
     * settings (the spec now REQUIRES is_eligible_search / is_eligible_checkout
     * per row) and the single target country grows into a list.
     */
    private function migration_2_1_0(): void
    {
        $defaults = [
            SettingsRepository::KEY_ENABLE_SEARCH => true,
            SettingsRepository::KEY_ENABLE_CHECKOUT => false,
            SettingsRepository::KEY_ENABLE_ADS => false,
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value, '', false);
            }
        }

        // Seed target_countries from the legacy single-country setting.
        if (get_option(SettingsRepository::KEY_TARGET_COUNTRIES, null) === null) {
            $single = (string) get_option(SettingsRepository::KEY_TARGET_COUNTRY, '');
            $seed = preg_match('/^[A-Za-z]{2}$/', $single) ? [strtoupper($single)] : [];
            add_option(SettingsRepository::KEY_TARGET_COUNTRIES, $seed, '', false);
        }
    }

    /**
     * 2.4.0 — every feed decision is made in FeedAgentX. The old Feed
     * Settings and Brand screens, the per-product eligibility panel and
     * the "who manages Ads settings" handover are gone, so their stored
     * values go too: nothing may keep shaping the feed from a setting no
     * screen shows. The feed falls back to plain defaults (store country,
     * automatic brand, no category filter) and FeedAgentX applies the
     * store's settings on top.
     */
    private function migration_2_4_0(): void
    {
        foreach ([
            SettingsRepository::KEY_ENABLE_SEARCH,
            SettingsRepository::KEY_ENABLE_CHECKOUT,
            SettingsRepository::KEY_ENABLE_ADS,
            SettingsRepository::KEY_ADS_METADATA,
            SettingsRepository::KEY_TARGET_COUNTRY,
            SettingsRepository::KEY_TARGET_COUNTRIES,
            SettingsRepository::KEY_RETURN_POLICY_URL,
            SettingsRepository::KEY_RETURN_DEADLINE_DAYS,
            SettingsRepository::KEY_BRAND_OVERRIDE,
            SettingsRepository::KEY_BRAND_SOURCE,
            SettingsRepository::KEY_EXCLUDED_CATEGORIES,
            SettingsRepository::KEY_INCLUDE_PRIVATE_PRODUCTS,
            SettingsRepository::KEY_DEFAULT_CONDITION,
            SettingsRepository::KEY_SETTINGS_OWNER,
            SettingsRepository::KEY_SETTINGS_OWNER_CLAIM,
            'faxc_accepts_returns',
        ] as $key) {
            delete_option($key);
        }
        foreach (['_faxc_eligible_search', '_faxc_eligible_checkout', '_faxc_eligible_ads'] as $meta_key) {
            delete_post_meta_by_key($meta_key);
        }
    }

    private function detect_target_country(): string
    {
        if (function_exists('WC')) {
            try {
                $country = \WC()->countries->get_base_country();
                if (is_string($country) && preg_match('/^[A-Z]{2}$/', $country)) {
                    return $country;
                }
            } catch (\Throwable) {
                // Fall through to default.
            }
        }
        return 'US';
    }
}
