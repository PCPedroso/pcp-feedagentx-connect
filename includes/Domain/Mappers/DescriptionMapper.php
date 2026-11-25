<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Description;

/**
 * Maps WooCommerce product descriptions to the ACP Description object.
 *
 * WC stores descriptions as HTML. We provide:
 *   - `plain` (normalized whitespace, max 5000 chars per spec)
 *   - `html` (sanitized via wp_kses_post)
 */
final class DescriptionMapper
{
    private const MAX_PLAIN_LENGTH = 5000;

    public function from_product(\WC_Product $product): ?Description
    {
        $html_raw = $product->get_description();

        // Variations sometimes have empty descriptions — fall back to parent.
        if (($html_raw === null || $html_raw === '') && $product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $html_raw = $parent->get_description();
            }
        }

        if ($html_raw === null || $html_raw === '') {
            // Try short description as a last resort.
            $html_raw = $product->get_short_description();
        }

        if ($html_raw === null || $html_raw === '') {
            return null;
        }

        $html = wp_kses_post($html_raw);

        $plain = wp_strip_all_tags($html_raw, true);
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
        $plain = $this->truncate($plain, self::MAX_PLAIN_LENGTH);

        if ($plain === '' && $html === '') {
            return null;
        }

        return new Description(
            plain: $plain !== '' ? $plain : null,
            html: $html !== '' ? $html : null,
        );
    }

    private function truncate(string $value, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $max) {
                return $value;
            }
            return mb_substr($value, 0, $max - 1, 'UTF-8') . '…';
        }
        return strlen($value) <= $max ? $value : substr($value, 0, $max - 1) . '…';
    }
}
