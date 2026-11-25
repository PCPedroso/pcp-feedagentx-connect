<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

/**
 * Thrown when request input or domain data fails validation.
 * `errors` is a list of per-field error descriptors.
 */
class ValidationException extends FeedAgentXException
{
    private array $errors;

    public function __construct(string $message = 'Validation failed', array $errors = [], array $context = [])
    {
        parent::__construct($message, $context);
        $this->errors = $errors;
    }

    public function get_errors(): array
    {
        return $this->errors;
    }

    public function get_context(): array
    {
        return parent::get_context() + ['errors' => $this->errors];
    }

    public function get_error_code(): string
    {
        return 'validation_error';
    }

    public function get_status_code(): int
    {
        return 422;
    }
}
