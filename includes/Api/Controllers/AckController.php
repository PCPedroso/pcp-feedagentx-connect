<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

// Domain exceptions carry structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use FeedAgentX\Infra\Exceptions\InvalidArgumentException;
use FeedAgentX\Services\DeltaService;

/**
 * Acknowledge endpoint: SaaS calls this after successfully consuming a batch
 * of changes. Deletes change-log entries up to (and including) `until`.
 *
 *   POST /feed/changes/ack
 *   { "until": "2026-05-05T10:30:00Z" }
 */
final class AckController extends BaseController
{
    public function __construct(
        private readonly DeltaService $delta,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->run($request, function (\WP_REST_Request $req) {
            if ($req->get_param('through_event_id') !== null) {
                $id = (int) $req->get_param('through_event_id');
                return ['acked_event_id' => (string) $id, 'deleted' => $this->delta->ack_event($id)];
            }
            $until_param = $req->get_param('until');
            if (! is_string($until_param) || $until_param === '') {
                throw new InvalidArgumentException(
                    '`until` is required (ISO 8601 UTC timestamp).',
                    ['received' => $until_param]
                );
            }

            try {
                $until = new \DateTimeImmutable($until_param, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                throw new InvalidArgumentException(
                    '`until` must be a valid ISO 8601 timestamp.',
                    ['received' => $until_param]
                );
            }

            $deleted = $this->delta->ack_until($until);

            return [
                'acked_until' => $until->format('c'),
                'deleted' => $deleted,
            ];
        });
    }
}
