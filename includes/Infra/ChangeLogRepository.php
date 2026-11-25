<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Change-log access layer for the faxc_change_log table.
 *
 * Logs every WooCommerce product/variation lifecycle event so SaaS can
 * incrementally sync via /feed/changes. Variation events are recorded against
 * the parent product ID so the SaaS sees one stable entity.
 */
final class ChangeLogRepository
{
    public const CHANGE_CREATED = 'created';
    public const CHANGE_UPDATED = 'updated';
    public const CHANGE_DELETED = 'deleted';
    public const CHANGE_PRICE = 'price';
    public const CHANGE_STOCK = 'stock';
    public const CHANGE_TAXONOMY = 'taxonomy';
    public const CHANGE_IMAGE = 'image';

    public function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'faxc_change_log';
    }

    public function log(int $product_id, string $change_type): void
    {
        if ($product_id <= 0) {
            return;
        }

        $post_type = get_post_type($product_id);
        if ($post_type !== 'product' && $post_type !== 'product_variation') {
            return;
        }

        // Variations are logged against the parent so the SaaS sees one entity.
        $log_id = $product_id;
        if ($post_type === 'product_variation') {
            $parent = wp_get_post_parent_id($product_id);
            if ($parent > 0) {
                $log_id = $parent;
                // Removing a variation changes its parent; it does not delete the parent.
                if ($change_type === self::CHANGE_DELETED) $change_type = self::CHANGE_UPDATED;
            }
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert(
            $this->table_name(),
            [
                'product_id' => $log_id,
                'change_type' => $change_type,
                'updated_at' => current_time('mysql', 1),
            ],
            ['%d', '%s', '%s']
        );
    }

    /**
     * Fetch DISTINCT product changes since `$since` (UTC), ordered ASC.
     *
     * @return array<int, array{product_id:int, change_type:string, updated_at:string}>
     */
    public function fetch_changes(?string $since_mysql, string $until_mysql, int $limit): array
    {
        global $wpdb;
        $table = $this->table_name();

        $sql = "SELECT product_id, change_type, updated_at FROM {$table} WHERE updated_at <= %s";
        $params = [$until_mysql];

        if ($since_mysql !== null) {
            $sql .= ' AND updated_at > %s';
            $params[] = $since_mysql;
        }

        // We sort by updated_at ASC so the SaaS can resume mid-batch.
        $sql .= ' ORDER BY updated_at ASC, id ASC LIMIT %d';
        $params[] = $limit;

        // Dynamic SQL contains fixed clauses and placeholders only; values are bound here.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        if (! is_array($rows)) {
            return [];
        }

        // Dedupe per product, keeping the most informative change_type.
        $deduped = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $row['product_id'] = $pid;
            $deduped[$pid] = $row;
        }

        return array_values($deduped);
    }

    public function latest_event_id(): int
    {
        global $wpdb;
        return max((int) $wpdb->get_var("SELECT MAX(id) FROM {$this->table_name()}"), $this->event_floor());
    }

    public function event_floor(): int
    {
        return max((int) get_option('faxc_acked_event_id', 0), (int) get_option('faxc_retired_event_id', 0));
    }

    public function fetch_events(int $after, int $until, int $limit): array
    {
        global $wpdb;
        // Page raw events BEFORE deduplication; the cursor advances by consumed event ID.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, product_id, change_type, updated_at FROM {$this->table_name()} WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d",
            $after, $until, $limit
        ), ARRAY_A);
        if (! is_array($rows) || ! empty($wpdb->last_error)) throw new \RuntimeException('Could not read the change log. Retry without advancing the cursor.');
        return $rows;
    }

    public function ack_event(int $id): int
    {
        global $wpdb;
        update_option('faxc_acked_event_id', max($id, (int) get_option('faxc_acked_event_id', 0)), false);
        return (int) $wpdb->query($wpdb->prepare("DELETE FROM {$this->table_name()} WHERE id <= %d", $id));
    }

    public function count_since(?string $since_mysql): int
    {
        global $wpdb;
        $table = $this->table_name();

        if ($since_mysql !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE updated_at > %s",
                $since_mysql
            ));
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    public function get_latest_timestamp(): ?string
    {
        global $wpdb;
        $table = $this->table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $value = $wpdb->get_var("SELECT MAX(updated_at) FROM {$table}");
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Delete all change log entries with updated_at <= $until_mysql.
     * Returns the number of deleted rows.
     */
    public function ack_until(string $until_mysql): int
    {
        global $wpdb;
        $table = $this->table_name();

        // A client behind a retired event must rescan instead of skipping missing history.
        $last = (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM {$table} WHERE updated_at <= %s", $until_mysql));
        update_option('faxc_retired_event_id', max($last, (int) get_option('faxc_retired_event_id', 0)), false);
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE updated_at <= %s",
            $until_mysql
        ));
    }

    /**
     * Bulk-delete entries older than $days (UTC).
     * Used by the retention cron.
     */
    public function purge_older_than(int $days): int
    {
        $threshold = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        return $this->ack_until($threshold);
    }

    public function truncate(): int
    {
        global $wpdb;
        $table = $this->table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        update_option('faxc_retired_event_id', $this->latest_event_id(), false);
        return (int) $wpdb->query("DELETE FROM {$table}");
    }
}
