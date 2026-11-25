<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ErrorDiagnostics;
use FeedAgentX\Infra\Logger;

/**
 * Keeps one field's failure from failing the whole product.
 *
 * Reading a product runs other plugins' and the theme's hooks (prices,
 * links, images, brands). When one of them throws, the field falls back
 * to the value stored on the product, or to nothing, and the failure is
 * recorded so the feed can report it. If the fallback throws as well,
 * the original error propagates.
 */
final class MappingGuard
{
    /** @var array<int, array<string, mixed>> */
    private static array $failures = [];

    /**
     * @template T
     * @param callable(): T $read
     * @param callable(): T $fallback
     * @return T
     */
    public static function run(string $field, callable $read, callable $fallback): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            try {
                $value = $fallback();
            } catch (\Throwable) {
                throw $e;
            }
            $failure = ['field' => $field] + ErrorDiagnostics::describe($e);
            self::$failures[] = $failure;
            Logger::warning('A field fell back after another component failed', $failure);
            return $value;
        }
    }

    public static function reset(): void
    {
        self::$failures = [];
    }

    /** @return array<int, array<string, mixed>> Failures since the last reset; clears them. */
    public static function take(): array
    {
        $failures = self::$failures;
        self::$failures = [];
        return $failures;
    }
}
