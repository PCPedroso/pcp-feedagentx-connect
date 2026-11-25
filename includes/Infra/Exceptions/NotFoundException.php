<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

class NotFoundException extends FeedAgentXException
{
    public function get_error_code(): string
    {
        return 'not_found';
    }

    public function get_status_code(): int
    {
        return 404;
    }
}
