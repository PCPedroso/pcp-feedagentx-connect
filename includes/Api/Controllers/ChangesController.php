<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\Cursor;
use FeedAgentX\Services\DeltaService;

final class ChangesController extends BaseController
{
    public function __construct(
        private readonly DeltaService $delta,
    ) {
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->run($request, function (\WP_REST_Request $req) {
            if ($req->get_param('after_event_id') !== null) {
                return $this->delta->get_event_changes((int) $req->get_param('after_event_id'), min(1000, max(1, (int) ($req->get_param('limit') ?: 500))));
            }
            $cursor = Cursor::from_request($req, default_limit: 500, max_limit: 1000);
            return $this->delta->get_changes($cursor);
        });
    }
}
