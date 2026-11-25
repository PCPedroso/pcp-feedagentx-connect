<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

// Domain exceptions carry structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use FeedAgentX\Infra\Exceptions\InvalidArgumentException;

/**
 * Time-based cursor for delta sync.
 *
 * `since` and `until` are captured before query execution to prevent races:
 * the SaaS reuses `until` as the next request's `since`, guaranteeing no
 * change is missed and no change is replayed.
 */
final class Cursor
{
    public function __construct(
        public readonly ?\DateTimeImmutable $since,
        public readonly \DateTimeImmutable $until,
        public readonly int $limit,
        public bool $has_more = false
    ) {
    }

    /**
     * Build a cursor from REST request parameters.
     *
     * @throws InvalidArgumentException if `since` is not parseable.
     */
    public static function from_request(\WP_REST_Request $request, int $default_limit = 500, int $max_limit = 1000): self
    {
        $since_param = $request->get_param('since');
        $since = null;

        if (is_string($since_param) && $since_param !== '') {
            try {
                $since = new \DateTimeImmutable($since_param, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                throw new InvalidArgumentException(
                    'Invalid "since" parameter; must be a valid ISO 8601 date.',
                    ['param' => $since_param]
                );
            }
        }

        $limit_param = (int) $request->get_param('limit');
        $limit = $limit_param > 0 ? min($limit_param, $max_limit) : $default_limit;

        // Capture "until" up front so we can return it to the caller as
        // the next-cursor value, regardless of how long the query takes.
        $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new self($since, $until, $limit);
    }

    public function format_since(): ?string
    {
        return $this->since?->format('Y-m-d H:i:s');
    }

    public function format_until(): string
    {
        return $this->until->format('Y-m-d H:i:s');
    }

    public function to_array(): array
    {
        return [
            'since' => $this->since?->format('c'),
            'until' => $this->until->format('c'),
            'limit' => $this->limit,
            'has_more' => $this->has_more,
        ];
    }
}
