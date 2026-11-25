<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

use FeedAgentX\Services\FeedService;

final class ItemController extends BaseController
{
    public function __construct(
        private readonly FeedService $feed,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->run($request, function (\WP_REST_Request $req) {
            $product_id = (int) $req['id'];
            return $this->feed->get_item($product_id);
        });
    }
}
