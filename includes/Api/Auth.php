<?php

namespace FeedAgentX\Api;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\Exceptions\RateLimitException;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Services\TokenService;

/**
 * REST permission callback. Pass on success, return WP_Error on failure
 * (because WordPress requires WP_Error for permission rejection).
 *
 * Auth flow:
 *   1. IP-based throttle for unauth attempts (cheapest check; protects
 *      hash_equals from being a CPU sink and the log file from spam).
 *   2. Bearer token extraction.
 *   3. Timing-safe token compare.
 *   4. Authenticated per-token throttle.
 */
final class Auth
{
    /**
     * Per-request memoization. WordPress invokes permission_callback more
     * than once per request in some pipelines (validation + dispatch), so
     * we cache the verdict by request-object identity to keep our rate
     * counters honest.
     *
     * @var array<int, bool|\WP_Error>
     */
    private array $request_cache = [];

    public function __construct(
        private readonly TokenService $tokens,
        private readonly RateLimiter $limiter,
        private readonly \FeedAgentX\Infra\SettingsRepository $settings,
    ) {
    }

    public function check(\WP_REST_Request $request): bool|\WP_Error
    {
        $cache_key = spl_object_id($request);
        if (array_key_exists($cache_key, $this->request_cache)) {
            return $this->request_cache[$cache_key];
        }

        $result = $this->do_check($request);
        $this->request_cache[$cache_key] = $result;
        return $result;
    }

    private function do_check(\WP_REST_Request $request): bool|\WP_Error
    {
        $token = self::extract_bearer_token($request);
        $ip = self::client_ip();

        if ($token === null || ! $this->tokens->matches($token)) {
            // Throttle BEFORE we surface the 401 so a flood of bad tokens
            // doesn't burn CPU on hash_equals or fill the log file.
            try {
                $this->limiter->enforce_unauth($ip);
            } catch (RateLimitException $e) {
                Logger::warning('Unauth rate limit hit', [
                    'route' => $request->get_route(),
                    'ip' => $ip,
                    'observed' => $e->get_context()['observed'] ?? null,
                ]);
                return $this->error(
                    $e->get_error_code(),
                    $e->getMessage(),
                    $e->get_status_code(),
                    $e->get_context()
                );
            }

            if ($token === null) {
                Logger::info('Missing authorization header.', [
                    'route' => $request->get_route(),
                    'ip' => $ip,
                ]);
                return $this->error(
                    'unauthorized',
                    __('Missing authentication token.', 'feedagentx-connect'),
                    401
                );
            }

            Logger::warning('Invalid token presented.', [
                'route' => $request->get_route(),
                'ip' => $ip,
            ]);
            return $this->error(
                'unauthorized',
                __('Invalid authentication token.', 'feedagentx-connect'),
                401
            );
        }

        // Authenticated path: enforce the per-token quota.
        try {
            $this->limiter->enforce_per_token(sha1($token));
        } catch (RateLimitException $e) {
            return $this->error(
                $e->get_error_code(),
                $e->getMessage(),
                $e->get_status_code(),
                $e->get_context()
            );
        }

        // The only thing a valid token leaves behind: when FeedAgentX last
        // read the catalog, so the admin screens can tell a connected
        // store from a new one. It changes nothing about the feed.
        $this->settings->record_app_contact();
        return true;
    }

    public static function extract_bearer_token(\WP_REST_Request $request): ?string
    {
        $header = $request->get_header('authorization');
        if (! is_string($header) || $header === '') {
            // Some hosts strip Authorization; check the common fallback.
            $header = $request->get_header('x_authorization');
        }
        if (! is_string($header) || $header === '') {
            return null;
        }
        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Best-effort client IP detection.
     *
     * Uses REMOTE_ADDR by default (the only un-spoofable value when the site
     * isn't behind a reverse proxy). If the site IS behind a trusted proxy
     * or CDN, the admin can hook the `faxc_client_ip` filter to read
     * X-Forwarded-For or CF-Connecting-IP instead.
     */
    public static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = trim($ip);

        /**
         * Filters the client IP used for rate limiting.
         *
         * @param string $ip Raw IP from REMOTE_ADDR.
         */
        $filtered = apply_filters('faxc_client_ip', $ip);
        return is_string($filtered) ? trim($filtered) : '';
    }

    private function error(string $code, string $message, int $status, array $details = []): \WP_Error
    {
        $data = ['status' => $status];
        if (! empty($details)) {
            $data += $details;
        }
        return new \WP_Error($code, $message, $data);
    }
}
