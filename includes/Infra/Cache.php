<?php

namespace FeedAgentX\Infra;

defined('ABSPATH') || exit;

/**
 * Thin wrapper over WP transients with a namespaced key prefix.
 *
 * Centralizing cache access lets us flush all plugin caches in one call
 * (e.g. on settings change or token rotation).
 */
final class Cache
{
    private const PREFIX = 'faxc_';
    private const INDEX_OPTION = 'faxc_cache_keys';

    public function get(string $key): mixed
    {
        $value = get_transient(self::PREFIX . $key);
        return $value === false ? null : $value;
    }

    public function set(string $key, mixed $value, int $ttl_seconds): void
    {
        set_transient(self::PREFIX . $key, $value, max(1, $ttl_seconds));
        $this->register_key($key);
    }

    public function forget(string $key): void
    {
        delete_transient(self::PREFIX . $key);
    }

    /**
     * Get-or-compute helper. Resolver runs only on cache miss.
     */
    public function remember(string $key, int $ttl_seconds, callable $resolver): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $resolver();
        $this->set($key, $value, $ttl_seconds);
        return $value;
    }

    public function flush_all(): void
    {
        $keys = get_option(self::INDEX_OPTION, []);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                delete_transient(self::PREFIX . $key);
            }
        }
        delete_option(self::INDEX_OPTION);
    }

    private function register_key(string $key): void
    {
        $keys = get_option(self::INDEX_OPTION, []);
        if (! is_array($keys)) {
            $keys = [];
        }
        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            // Bound the index so it can't grow unbounded.
            if (count($keys) > 500) {
                $keys = array_slice($keys, -500);
            }
            update_option(self::INDEX_OPTION, $keys, false);
        }
    }
}
