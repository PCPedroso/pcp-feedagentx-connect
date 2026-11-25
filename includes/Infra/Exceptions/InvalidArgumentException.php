<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

class InvalidArgumentException extends FeedAgentXException
{
    public function get_error_code(): string
    {
        return 'invalid_argument';
    }

    public function get_status_code(): int
    {
        return 400;
    }
}
