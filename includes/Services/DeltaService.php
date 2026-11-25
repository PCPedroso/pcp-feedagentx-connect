<?php

namespace FeedAgentX\Services;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\Cursor;

/**
 * Coordinates incremental sync via the change log.
 *
 * `until` is captured before reading so SaaS can safely reuse it as the next
 * `since` value without missing or duplicating events.
 */
final class DeltaService
{
    public function __construct(
        private readonly ChangeLogRepository $change_log,
    ) {
    }

    /**
     * @return array{
     *   since: ?string,
     *   until: string,
     *   items: array<int, array{product_id:int, change_type:string, updated_at:string}>,
     *   count: int,
     *   has_more: bool,
     * }
     */
    public function get_changes(Cursor $cursor): array
    {
        $since_mysql = $cursor->format_since();
        $until_mysql = $cursor->format_until();
        $items = $this->change_log->fetch_changes($since_mysql, $until_mysql, $cursor->limit + 1);

        $has_more = count($items) > $cursor->limit;
        if ($has_more) {
            $items = array_slice($items, 0, $cursor->limit);
        }

        return [
            'since' => $cursor->since?->format('c'),
            'until' => $cursor->until->format('c'),
            'items' => $items,
            'count' => count($items),
            'has_more' => $has_more,
        ];
    }

    public function get_event_changes(int $after, int $limit): array
    {
        $until = $this->change_log->latest_event_id();
        if ($after < $this->change_log->event_floor() || $after > $until) throw new \FeedAgentX\Infra\Exceptions\InvalidArgumentException('Change cursor exceeds this store history. Run a full sync.');
        $rows = $this->change_log->fetch_events($after, $until, $limit + 1);
        $has_more = count($rows) > $limit;
        if ($has_more) array_pop($rows);
        $next = empty($rows) ? $until : (int) end($rows)['id'];
        $items = [];
        foreach ($rows as $row) {
            $row['product_id'] = (int) $row['product_id'];
            $items[$row['product_id']] = $row;
        }
        return ['items' => array_values($items), 'count' => count($items), 'has_more' => $has_more, 'next_event_id' => (string) $next, 'until_event_id' => (string) $until];
    }

    public function ack_event(int $id): int
    {
        if ($id < 0 || $id > $this->change_log->latest_event_id()) throw new \FeedAgentX\Infra\Exceptions\InvalidArgumentException('Invalid event acknowledgment.');
        return $this->change_log->ack_event($id);
    }

    /**
     * Acknowledge: delete change log entries up to the supplied timestamp.
     * Returns count of removed rows.
     */
    public function ack_until(\DateTimeImmutable $until): int
    {
        return $this->change_log->ack_until($until->format('Y-m-d H:i:s'));
    }
}
