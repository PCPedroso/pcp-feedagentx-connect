<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\MeasurementSettings;

/**
 * HTTP client for OpenAI's Conversions API.
 *
 * Requests go from this site straight to OpenAI; FeedAgentX is not in the
 * path. The key authenticates event sending only and is never logged or
 * echoed back in a message.
 */
final class ConversionsApi
{
    public const ENDPOINT = 'https://bzr.openai.com/v1/events';

    /** Identifies this integration on every request, per OpenAI's partner guidance. */
    public const INTEGRATION_SOURCE = 'feedagentx';

    private const TIMEOUT = 10;
    private const MESSAGE_LIMIT = 300;

    public function __construct(
        private readonly MeasurementSettings $settings,
    ) {
    }

    public function endpoint(): string
    {
        $url = defined('FEEDAGENTX_CAPI_ENDPOINT') ? (string) FEEDAGENTX_CAPI_ENDPOINT : self::ENDPOINT;
        /** @var string $url */
        $url = apply_filters('feedagentx_capi_endpoint', $url);
        return $url;
    }

    /**
     * @param list<array> $events Up to 1,000 events; one invalid event fails the batch.
     * @return array{ok: bool, status: int, retryable: bool, message: string}
     */
    public function send(array $events, bool $validate_only = false): array
    {
        $pixel_id = $this->settings->get_pixel_id();
        $key = $this->settings->get_capi_key();
        if ($pixel_id === '' || $key === '') {
            return self::result(false, 0, false, __('Add your Pixel ID and Conversions API key first.', 'feedagentx-connect'));
        }

        $response = wp_remote_post(
            add_query_arg('pid', rawurlencode($pixel_id), $this->endpoint()),
            [
                'timeout' => self::TIMEOUT,
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                ],
                'body' => (string) wp_json_encode([
                    'integration_source' => self::INTEGRATION_SOURCE,
                    'validate_only' => $validate_only,
                    'events' => array_values($events),
                ]),
                'data_format' => 'body',
            ]
        );

        if (is_wp_error($response)) {
            return self::result(false, 0, true, self::clip($response->get_error_message()));
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status >= 200 && $status < 300) {
            return self::result(true, $status, false, '');
        }

        return self::result(
            false,
            $status,
            $status >= 500 || $status === 408 || $status === 429,
            sprintf('HTTP %d: %s', $status, self::error_message((string) wp_remote_retrieve_body($response)))
        );
    }

    /** OpenAI's error text if the body carries one, otherwise the raw start of the body. */
    public static function error_message(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $error = $json['error'] ?? null;
            if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
                return self::clip($error['message']);
            }
            if (is_string($error)) {
                return self::clip($error);
            }
            if (isset($json['message']) && is_string($json['message'])) {
                return self::clip($json['message']);
            }
        }
        $body = trim(wp_strip_all_tags($body));
        return $body === '' ? __('No details returned.', 'feedagentx-connect') : self::clip($body);
    }

    private static function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return strlen($text) > self::MESSAGE_LIMIT ? substr($text, 0, self::MESSAGE_LIMIT) . '…' : $text;
    }

    /** @return array{ok: bool, status: int, retryable: bool, message: string} */
    private static function result(bool $ok, int $status, bool $retryable, string $message): array
    {
        return ['ok' => $ok, 'status' => $status, 'retryable' => $retryable, 'message' => $message];
    }
}
