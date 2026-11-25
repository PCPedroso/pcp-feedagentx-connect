<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

class AuthException extends FeedAgentXException
{
    public function get_error_code(): string
    {
        return 'unauthorized';
    }

    public function get_status_code(): int
    {
        return 401;
    }
}
