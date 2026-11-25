<?php

namespace FeedAgentX\Services;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Mappers\SellerMapper;
use FeedAgentX\Infra\Cache;
use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\ProductRepository;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Aggregates a system snapshot for the /health endpoint and the diagnostics tab.
 */
final class HealthService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ProductRepository $products,
        private readonly ChangeLogRepository $change_log,
        private readonly Cache $cache,
        private readonly SellerMapper $seller_mapper,
    ) {
    }

    /** Drop the cached snapshot, after a change it must show at once. */
    public function forget(): void
    {
        $this->cache->forget('health_snapshot');
    }

    public function snapshot(): array
    {
        $snapshot = $this->cache->remember('health_snapshot', 30, function (): array {
            $latest = $this->change_log->get_latest_timestamp();

            return [
                'status' => 'ok',
                'plugin_version' => defined('FAXC_VERSION') ? FAXC_VERSION : 'unknown',
                'schema_version' => $this->settings->get_schema_version(),
                'wp_version' => get_bloginfo('version'),
                'woo_version' => $this->detect_woo_version(),
                'php_version' => PHP_VERSION,
                'site_timezone' => wp_timezone_string(),
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : null,
                'target_country' => $this->settings->get_target_country(),
                // Merchant defaults for the flat feed — the SaaS captures
                // these on every pull so its fallback chain tracks current
                // plugin settings (WP settings edits don't hit the change log).
                'store_country' => $this->settings->get_store_country(),
                'target_countries' => $this->settings->get_target_countries(),
                'seller' => array_filter($this->seller_mapper->build_header_block(), static fn ($v) => $v !== null),
                'ads_metadata' => (object) $this->settings->get_ads_metadata(),
                'eligibility' => [
                    'search' => $this->settings->is_search_enabled(),
                    'checkout' => $this->settings->is_checkout_enabled(),
                    'ads' => $this->settings->is_ads_enabled(),
                ],
                // Since 2.4 the plugin filters no categories: FeedAgentX
                // receives every published product and decides. Kept, and
                // always empty, so FeedAgentX can tell this from an old
                // plugin that cannot report a filter at all.
                'excluded_categories' => [],
                'totals' => [
                    'products' => $this->products->count_total(),
                    'variations' => $this->products->count_variations(),
                    'pending_changes' => $this->change_log->count_since(null),
                ],
                'last_change_at' => $latest !== null
                    ? gmdate('c', strtotime($latest . ' UTC'))
                    : null,
                'token_generated_at' => $this->settings->get_token_generated_at(),
            ];
        });
        $snapshot['change_cursor'] = 'event:' . $this->change_log->latest_event_id();
        $snapshot['change_floor'] = (string) $this->change_log->event_floor();
        return $snapshot;
    }

    private function detect_woo_version(): ?string
    {
        if (function_exists('WC')) {
            try {
                return \WC()->version ?? null;
            } catch (\Throwable) {
                return null;
            }
        }
        return null;
    }
}
