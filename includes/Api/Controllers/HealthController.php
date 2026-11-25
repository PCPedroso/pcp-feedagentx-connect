<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

use FeedAgentX\Services\HealthService;

final class HealthController extends BaseController
{
    public function __construct(
        private readonly HealthService $health,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->run($request, fn () => $this->health->snapshot());
    }
}
