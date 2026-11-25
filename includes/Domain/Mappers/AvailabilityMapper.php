<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Availability;

/**
 * Maps WooCommerce stock state to the ACP Availability object.
 *
 * WC stock_status values: 'instock', 'outofstock', 'onbackorder'.
 * Unreadable/unknown stock statuses map to 'unknown'. Pre-orders are detected
 * via WooCommerce Pre-Orders (`_wc_pre_orders_availability_datetime` meta,
 * or `class_exists('WC_Pre_Orders')` plus that meta).
 */
final class AvailabilityMapper
{
    private const PRE_ORDER_META_KEY = '_wc_pre_orders_availability_datetime';

    public function from_product(\WC_Product $product): Availability
    {
        $stock_status = $product->get_stock_status();
        $is_purchasable = $product->is_purchasable() && $product->is_in_stock();

        $status = match ($stock_status) {
            'instock' => Availability::IN_STOCK,
            'outofstock' => Availability::OUT_OF_STOCK,
            'onbackorder' => Availability::BACKORDER,
            default => Availability::UNKNOWN,
        };

        // WooCommerce Pre-Orders: a scheduled availability date marks the
        // product as pre_order regardless of the raw stock status.
        if ($this->resolve_pre_order_date($product) !== null) {
            return new Availability(
                available: $is_purchasable,
                status: Availability::PRE_ORDER,
            );
        }

        // If the product is unpurchasable for any reason, force out_of_stock.
        if (! $is_purchasable && $status === Availability::IN_STOCK) {
            $status = Availability::OUT_OF_STOCK;
        }

        return new Availability(
            available: $is_purchasable,
            status: $status,
        );
    }

    /**
     * Pre-order availability date (ISO 8601) from WooCommerce Pre-Orders meta.
     * Variations fall back to their parent's meta. Null when no (valid) date
     * is stored — which also means "not a pre-order".
     */
    /**
     * The stock status saved on the product, without other plugins'
     * purchasability hooks. Used only when those hooks fail.
     */
    public function from_stored(\WC_Product $product): Availability
    {
        $status = match ((string) $product->get_stock_status('edit')) {
            'instock' => Availability::IN_STOCK,
            'onbackorder' => Availability::BACKORDER,
            'outofstock' => Availability::OUT_OF_STOCK,
            default => Availability::UNKNOWN,
        };

        return new Availability(
            available: in_array($status, [Availability::IN_STOCK, Availability::BACKORDER], true),
            status: $status,
        );
    }

    public function resolve_pre_order_date(\WC_Product $product): ?string
    {
        $raw = $product->get_meta(self::PRE_ORDER_META_KEY);

        if (($raw === '' || $raw === null) && $product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $raw = $parent->get_meta(self::PRE_ORDER_META_KEY);
            }
        }

        if (! is_scalar($raw) || (string) $raw === '') {
            return null;
        }

        // WC Pre-Orders stores a unix timestamp; tolerate datetime strings too.
        $timestamp = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
        if (! is_int($timestamp) || $timestamp <= 0) {
            return null;
        }

        return gmdate('c', $timestamp);
    }
}
