<?php
namespace FeedAgentX\Domain\Mappers;
defined('ABSPATH') || exit;

/** WordPress IDs survive SKU edits and do not reuse another product's old SKU. */
final class StableId
{
    public static function for_product(\WC_Product $product): string
    {
        return 'wc-' . $product->get_id();
    }
}
