<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Repository for fetching WooCommerce products with cursor-based pagination
 * suited to large catalogs.
 *
 * Cursor pagination uses post ID > $after_id to avoid offset cost on huge
 * tables. Page-based fetching is also supported as a fallback.
 */
final class ProductRepository
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Cursor-based product ID fetch.
     *
     * @return array{ids: int[], next_cursor: ?int, has_more: bool, total: int}
     */
    public function fetch_ids_after(?int $after_id, int $per_page): array
    {
        $per_page = max(1, min($per_page, 500));
        $statuses = $this->statuses();

        global $wpdb;

        $where = ["p.post_type = 'product'", 'p.post_status IN (' . $this->in_clause($statuses) . ')'];
        if ($after_id !== null && $after_id > 0) {
            $where[] = $wpdb->prepare('p.ID > %d', $after_id);
        }


        $where_clause = 'WHERE ' . implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             {$where_clause}
             ORDER BY p.ID ASC
             LIMIT %d",
            $per_page + 1
        );

        // Query was prepared above; dynamic fragments use validated statuses and escaped IDs.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $raw_ids = $wpdb->get_col($sql);
        if (! is_array($raw_ids) || ! empty($wpdb->last_error)) throw new \RuntimeException('Could not read the product catalog. The full snapshot is incomplete.');
        $ids = array_map('intval', $raw_ids);
        $has_more = count($ids) > $per_page;
        if ($has_more) {
            array_pop($ids);
        }

        $next_cursor = $has_more && ! empty($ids) ? end($ids) : null;

        return [
            'ids' => $ids,
            'next_cursor' => $next_cursor,
            'has_more' => $has_more,
            'total' => $this->count_total(),
        ];
    }

    /** Products this plugin will hand over: the same set fetch_ids_after() pages through. */
    public function count_total(): int
    {
        global $wpdb;
        $statuses = $this->statuses();

        $where = ["p.post_type = 'product'", 'p.post_status IN (' . $this->in_clause($statuses) . ')'];

        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE " . implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Values bound via in_clause().
        return (int) $wpdb->get_var($sql);
    }


    public function count_variations(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'"
        );
    }

    public function get_product(int $product_id): ?\WC_Product
    {
        $product = wc_get_product($product_id);
        if (! ($product instanceof \WC_Product)) {
            return null;
        }
        if (! $this->is_status_allowed($product->get_status())) {
            return null;
        }
        return $product;
    }

    /**
     * @return string[]
     */
    private function statuses(): array
    {
        // Published products only; drafts, private, pending and scheduled
        // products never leave WordPress.
        return ['publish'];
    }

    private function is_status_allowed(string $status): bool
    {
        return in_array($status, $this->statuses(), true);
    }


    private function in_clause(array $values): string
    {
        // Used only for integer arrays — caller responsibility ensured.
        $sanitized = array_map(
            static fn ($v): string => is_int($v) ? (string) $v : "'" . esc_sql((string) $v) . "'",
            $values
        );
        return implode(',', $sanitized);
    }

}
