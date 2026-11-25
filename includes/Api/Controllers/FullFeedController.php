<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

use FeedAgentX\Services\FeedService;

final class FullFeedController extends BaseController
{
    public function __construct(
        private readonly FeedService $feed,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->run($request, function (\WP_REST_Request $req) {
            $after_id = (int) $req->get_param('after_id') ?: null;
            $per_page = (int) $req->get_param('per_page') ?: 100;

            return $this->feed->get_full_feed_page($after_id, $per_page);
        });
    }
}
