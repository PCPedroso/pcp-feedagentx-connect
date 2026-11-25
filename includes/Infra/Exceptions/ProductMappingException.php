<?php

namespace FeedAgentX\Infra\Exceptions;

defined('ABSPATH') || exit;

// Carries structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * A product could not be read even after its fallbacks. The cause is the
 * previous exception; the controller reports both as an internal error.
 */
final class ProductMappingException extends \RuntimeException
{
    public function __construct(
        public readonly int $product_id,
        \Throwable $previous,
    ) {
        parent::__construct(sprintf('Product %d could not be read.', $product_id), 0, $previous);
    }
}
