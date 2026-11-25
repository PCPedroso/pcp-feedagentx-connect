<?php

namespace FeedAgentX\Domain\Validation;

defined('ABSPATH') || exit;

/**
 * Aggregates errors and warnings from validation runs.
 *
 * Errors fail the validation pass; warnings are advisory (e.g. missing GTIN).
 */
final class ValidationResult
{
    /** @var array<int, array{path:string, code:string, message:string, context?:array}> */
    private array $errors = [];

    /** @var array<int, array{path:string, code:string, message:string, context?:array}> */
    private array $warnings = [];

    public function add_error(string $path, string $code, string $message, array $context = []): void
    {
        $this->errors[] = compact('path', 'code', 'message', 'context');
    }

    public function add_warning(string $path, string $code, string $message, array $context = []): void
    {
        $this->warnings[] = compact('path', 'code', 'message', 'context');
    }

    public function merge(ValidationResult $other, string $path_prefix = ''): void
    {
        foreach ($other->errors as $err) {
            $err['path'] = $this->prefix_path($path_prefix, $err['path']);
            $this->errors[] = $err;
        }
        foreach ($other->warnings as $warn) {
            $warn['path'] = $this->prefix_path($path_prefix, $warn['path']);
            $this->warnings[] = $warn;
        }
    }

    public function is_valid(): bool
    {
        return empty($this->errors);
    }

    /** @return array<int, array{path:string, code:string, message:string, context?:array}> */
    public function get_errors(): array
    {
        return $this->errors;
    }

    /** @return array<int, array{path:string, code:string, message:string, context?:array}> */
    public function get_warnings(): array
    {
        return $this->warnings;
    }

    public function to_array(): array
    {
        return [
            'valid' => $this->is_valid(),
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    private function prefix_path(string $prefix, string $path): string
    {
        if ($prefix === '') {
            return $path;
        }
        if ($path === '') {
            return $prefix;
        }
        return $prefix . '.' . $path;
    }
}
