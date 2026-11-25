<?php

namespace FeedAgentX\Api;

defined('ABSPATH') || exit;

use FeedAgentX\Api\Controllers\AckController;
use FeedAgentX\Api\Controllers\ChangesController;
use FeedAgentX\Api\Controllers\FullFeedController;
use FeedAgentX\Api\Controllers\HealthController;
use FeedAgentX\Api\Controllers\ItemController;

/**
 * Wires REST routes to controller methods. Pure registration — no business
 * logic lives here.
 *
 * All routes share the same Auth permission callback (Bearer token + rate limit).
 */
final class RestRoutes
{
    public const NAMESPACE = 'feedagentx/v1';

    public function __construct(
        private readonly Auth $auth,
        private readonly HealthController $health,
        private readonly FullFeedController $full_feed,
        private readonly ChangesController $changes,
        private readonly ItemController $item,
        private readonly AckController $ack,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        $permission = [$this->auth, 'check'];

        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->health, 'handle'],
            'permission_callback' => $permission,
        ]);

        register_rest_route(self::NAMESPACE, '/feed/full', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->full_feed, 'handle'],
            'permission_callback' => $permission,
            'args' => [
                'after_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/feed/changes', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->changes, 'handle'],
            'permission_callback' => $permission,
            'args' => [
                'after_event_id' => ['type' => 'integer', 'minimum' => 0, 'required' => false],
                'since' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'required' => false,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/feed/changes/ack', [
            'methods' => \WP_REST_Server::CREATABLE, // POST
            'callback' => [$this->ack, 'handle'],
            'permission_callback' => $permission,
            'args' => [
                'through_event_id' => ['type' => 'integer', 'minimum' => 0, 'required' => false],
                'until' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/item/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this->item, 'handle'],
            'permission_callback' => $permission,
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'validate_callback' => static fn ($value): bool => is_numeric($value) && (int) $value > 0,
                ],
            ],
        ]);
    }
}
