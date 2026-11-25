<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

class RateLimitException extends FeedAgentXException
{
    public function get_error_code(): string
    {
        return 'rate_limited';
    }

    public function get_status_code(): int
    {
        return 429;
    }
}
