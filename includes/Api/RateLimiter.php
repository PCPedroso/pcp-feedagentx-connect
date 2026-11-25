<?php

namespace FeedAgentX\Api;

defined('ABSPATH') || exit;

// Domain exceptions carry structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use FeedAgentX\Infra\Cache;
use FeedAgentX\Infra\Exceptions\RateLimitException;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Sliding-minute rate limiter backed by transients.
 *
 * Generic primitive: callers supply an opaque bucket key and a per-minute
 * limit. Convenience helpers wrap it for the two flows we actually use:
 *
 *   - enforce_per_token() — for authenticated requests
 *   - enforce_unauth()    — IP-based; protects 401 responses from log spam
 *                           and CPU burn (hash_equals on every bad token)
 *
 * Counter resets every wall-clock minute; not a true sliding window but
 * cheap, predictable, and adequate for plugin protection.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Cache $cache,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function enforce_per_token(string $token_hash): void
    {
        $this->enforce(
            'token_' . $token_hash,
            $this->settings->get_rate_limit_per_minute(),
        );
    }

    public function enforce_unauth(string $ip): void
    {
        // Empty IP = unable to identify caller; fall back to a shared bucket
        // so the limiter still applies (slightly aggressive, but safer than
        // letting unidentifiable callers bypass).
        $segment = $ip !== '' ? 'ip_' . sha1($ip) : 'ip_unknown';

        $this->enforce(
            $segment,
            $this->settings->get_unauth_rate_limit_per_minute(),
        );
    }

    /**
     * @throws RateLimitException
     */
    public function enforce(string $key_segment, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $bucket = (int) floor(time() / 60);
        $key = sprintf('rate_%s_%d', $key_segment, $bucket);

        $count = (int) ($this->cache->get($key) ?? 0);
        $count++;

        // 70-second TTL so the bucket survives slight clock drift.
        $this->cache->set($key, $count, 70);

        if ($count > $limit) {
            throw new RateLimitException(
                'Too many requests. Try again in a minute.',
                ['limit_per_minute' => $limit, 'observed' => $count]
            );
        }
    }
}
