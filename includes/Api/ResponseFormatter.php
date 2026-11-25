<?php

namespace FeedAgentX\Api;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\Exceptions\FeedAgentXException;

/**
 * Builds uniform success/error responses across all REST endpoints.
 *
 * Error envelope shape:
 *   {
 *     "error": {
 *       "code": "validation_error",
 *       "message": "Human-readable explanation",
 *       "details": { ... optional context ... }
 *     }
 *   }
 *
 * Success responses are returned as-is so callers can shape per-endpoint payloads.
 */
final class ResponseFormatter
{
    public static function success(array $data, int $status = 200, array $headers = []): \WP_REST_Response
    {
        $response = new \WP_REST_Response($data, $status);
        foreach ($headers as $key => $value) {
            $response->header($key, (string) $value);
        }
        return $response;
    }

    public static function error(string $code, string $message, int $status = 400, array $details = [], array $headers = []): \WP_REST_Response
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if (! empty($details)) {
            $payload['error']['details'] = $details;
        }

        $response = new \WP_REST_Response($payload, $status);
        foreach ($headers as $key => $value) {
            $response->header($key, (string) $value);
        }
        return $response;
    }

    public static function from_exception(FeedAgentXException $e, array $headers = []): \WP_REST_Response
    {
        return self::error(
            $e->get_error_code(),
            $e->getMessage() !== '' ? $e->getMessage() : 'An error occurred.',
            $e->get_status_code(),
            $e->get_context(),
            $headers
        );
    }
}
