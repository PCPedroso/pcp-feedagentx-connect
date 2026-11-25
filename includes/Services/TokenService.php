<?php

namespace FeedAgentX\Services;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\Cache;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Token lifecycle: read, generate, rotate, validate.
 *
 * Validation uses hash_equals (timing-safe). Rotation also flushes the rate
 * limiter and request caches keyed off the previous token.
 */
final class TokenService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Cache $cache,
    ) {
    }

    public function current(): string
    {
        return $this->settings->get_read_token();
    }

    public function rotate(): string
    {
        $new = wp_generate_password(40, false, false);
        $this->settings->set_read_token($new);
        $this->cache->flush_all();
        Logger::info('API token rotated.');
        return $new;
    }

    public function ensure_exists(): string
    {
        $token = $this->current();
        if ($token === '') {
            return $this->rotate();
        }
        return $token;
    }

    public function matches(?string $supplied): bool
    {
        if ($supplied === null || $supplied === '') {
            return false;
        }
        $stored = $this->current();
        if ($stored === '') {
            return false;
        }
        return hash_equals($stored, $supplied);
    }
}
