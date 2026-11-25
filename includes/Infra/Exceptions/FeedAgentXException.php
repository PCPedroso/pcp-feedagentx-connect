<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

/**
 * Base exception. All plugin exceptions extend this.
 * Carries a structured context array for logging + API responses.
 */
class FeedAgentXException extends \RuntimeException
{
    protected array $context;

    public function __construct(string $message = '', array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function get_context(): array
    {
        return $this->context;
    }

    public function get_error_code(): string
    {
        return 'feedagentx_error';
    }

    public function get_status_code(): int
    {
        return 500;
    }
}
