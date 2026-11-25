<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Plugin lifecycle hooks: activation, deactivation, uninstall.
 *
 * Activation is idempotent: it runs migrations to bring schema and options
 * up to the bundled version. It never destroys data.
 */
final class Installer
{
    public static function activate(): void
    {
        $settings = new SettingsRepository();
        $migrations = new Migrations($settings);
        $migrations->run(FAXC_VERSION);

        $settings->installed_at();

        // Ensure a token exists.
        if ($settings->get_read_token() === '') {
            $settings->set_read_token(wp_generate_password(40, false, false));
        }

        // Schedule the cron jobs (idempotent).
        if (! wp_next_scheduled('faxc_daily_maintenance')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'faxc_daily_maintenance');
        }

        // Purchases queued before a deactivation lost their scheduled send.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('faxc_capi_requeue', [], 'feedagentx');
        }
    }

    public static function deactivate(): void
    {
        $timestamp = wp_next_scheduled('faxc_daily_maintenance');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'faxc_daily_maintenance');
        }
        wp_clear_scheduled_hook('faxc_daily_maintenance');

        // Queued purchases would otherwise fail in Action Scheduler with
        // "no callbacks registered" while the plugin is inactive.
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('faxc_capi_send_order');
        }
        wp_clear_scheduled_hook('faxc_capi_send_order');

        // Clear plugin transient cache.
        (new Cache())->flush_all();
    }

    /**
     * Uninstall removes all plugin data.
     * Called from uninstall.php.
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'faxc_change_log';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        $option_keys = [
            SettingsRepository::KEY_READ_TOKEN,
            SettingsRepository::KEY_TOKEN_GENERATED_AT,
            SettingsRepository::KEY_SCHEMA_VERSION,
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
            // Left behind, a reinstall starts locked: every per-product
            // Ads control dead, pointing at an app the merchant may no
            // longer use.
            SettingsRepository::KEY_SETTINGS_OWNER,
            SettingsRepository::KEY_SETTINGS_OWNER_CLAIM,
            SettingsRepository::KEY_RATE_LIMIT_PER_MINUTE,
            SettingsRepository::KEY_UNAUTH_RATE_LIMIT_PER_MINUTE,
            SettingsRepository::KEY_LOG_RETENTION_DAYS,
            SettingsRepository::KEY_LOG_LEVEL,
            SettingsRepository::KEY_LOG_ENABLED,
            SettingsRepository::KEY_CHANGE_LOG_RETENTION_DAYS,
            SettingsRepository::KEY_INCLUDE_PRIVATE_PRODUCTS,
            SettingsRepository::KEY_DEFAULT_CONDITION,
            SettingsRepository::KEY_LAST_APP_CONTACT,
            SettingsRepository::KEY_INSTALLED_AT,
            'faxc_cache_keys',
            'faxc_accepts_returns',
            'faxc_acked_event_id',
            'faxc_retired_event_id',
        ];
        foreach (array_merge($option_keys, MeasurementSettings::option_keys()) as $key) {
            delete_option($key);
        }

        // Per-product eligibility overrides (post meta).
        delete_post_meta_by_key('_faxc_eligible_search');
        delete_post_meta_by_key('_faxc_eligible_checkout');
        delete_post_meta_by_key('_faxc_eligible_ads');

        // Measurement state kept on orders: consent, OpenAI's attribution
        // cookies and delivery status. Orders live in posts or, with HPOS,
        // in their own meta table.
        $order_meta = ['_faxc_mc', '_faxc_oppref', '_faxc_obref', '_faxc_event_id', '_faxc_pixel_fired', '_faxc_capi_state', '_faxc_capi_state_at'];
        foreach ($order_meta as $key) {
            delete_post_meta_by_key($key);
        }
        $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta)) === $hpos_meta) {
            foreach ($order_meta as $key) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                $wpdb->delete($hpos_meta, ['meta_key' => $key]);
            }
        }
        delete_metadata('user', 0, 'faxc_pixel_announce_dismissed', '', true);
        delete_metadata('user', 0, 'faxc_review_prompt_dismissed', '', true);

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('faxc_capi_send_order');
            as_unschedule_all_actions('faxc_capi_requeue');
        }
        wp_clear_scheduled_hook('faxc_capi_send_order');
        wp_clear_scheduled_hook('faxc_daily_maintenance');
    }
}
