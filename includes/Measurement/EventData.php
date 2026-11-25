<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Currency\MinorUnitsConverter;
use FeedAgentX\Domain\Mappers\StableId;
use FeedAgentX\Infra\MeasurementSettings;

/**
 * Builds the `data` object of OpenAI measurement events from WooCommerce
 * products, carts and orders.
 *
 * Item IDs are the same `wc-<ID>` identifiers the product feed uses, so a
 * measured product and a feed row are the same thing to OpenAI. Amounts
 * are integers in the currency's minor unit (USD 25.99 → 2599).
 */
final class EventData
{
    public const META_EVENT_ID = '_faxc_event_id';

    private const NAME_LIMIT = 200;

    public function __construct(
        private readonly MinorUnitsConverter $money,
        private readonly MeasurementSettings $settings,
    ) {
    }

    /**
     * One ID per order, shared by the browser event and the server event
     * so OpenAI counts the purchase once. The site key keeps two stores
     * that share a pixel from colliding on the same order number.
     *
     * Kept on the order the first time it is made, so both sides read the
     * same value whatever changes between the two requests.
     */
    public function order_event_id(\WC_Order $order): string
    {
        $saved = (string) $order->get_meta(self::META_EVENT_ID);
        if ($saved !== '') {
            return $saved;
        }
        $id = 'wc-order-' . $order->get_id() . '-' . $this->settings->site_key();
        if ($order->get_id()) {
            $order->update_meta_data(self::META_EVENT_ID, $id);
            $order->save_meta_data();
        }
        return $id;
    }

    public function product_viewed(\WC_Product $product): array
    {
        $currency = (string) get_woocommerce_currency();
        $unit = $this->minor(wc_get_price_to_display($product), $currency);
        $item = $this->item($product, 1, $unit, $currency, false);
        // A variable product's page shows the group, not one variation.
        if ($product->is_type('variable')) {
            $item['content_type'] = 'product_group';
        }
        return $this->contents($unit, $currency, [$item]);
    }

    public function item_added(\WC_Product $product, int $quantity): array
    {
        $quantity = max(1, $quantity);
        $currency = (string) get_woocommerce_currency();
        $unit = $this->minor(wc_get_price_to_display($product), $currency);
        $total = $unit === null ? null : $unit * $quantity;
        return $this->contents($total, $currency, [$this->item($product, $quantity, $unit, $currency, false)]);
    }

    public function cart(\WC_Cart $cart): array
    {
        $currency = (string) get_woocommerce_currency();
        $incl_tax = $cart->display_prices_including_tax();
        $items = [];
        foreach ($cart->get_cart() as $line) {
            $product = $line['data'] ?? null;
            if (! $product instanceof \WC_Product) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $line_total = (float) ($line['line_total'] ?? 0) + ($incl_tax ? (float) ($line['line_tax'] ?? 0) : 0.0);
            $items[] = $this->item($product, $quantity, $this->minor($line_total / $quantity, $currency), $currency, false);
        }
        return $this->contents($this->minor($cart->get_total('edit'), $currency), $currency, $items);
    }

    /**
     * @param bool $server Adds the Conversions API-only fields (group_id,
     *                     variant_dict); the browser Pixel rejects them.
     */
    public function order(\WC_Order $order, bool $server): array
    {
        $currency = (string) $order->get_currency();
        $incl_tax = get_option('woocommerce_tax_display_cart') === 'incl';
        $items = [];
        foreach ($order->get_items('line_item') as $line) {
            if (! $line instanceof \WC_Order_Item_Product) {
                continue;
            }
            $quantity = max(1, (int) $line->get_quantity());
            $line_total = (float) $line->get_total() + ($incl_tax ? (float) $line->get_total_tax() : 0.0);
            $unit = $this->minor($line_total / $quantity, $currency);
            $product = $line->get_product();
            if ($product instanceof \WC_Product) {
                $items[] = $this->item($product, $quantity, $unit, $currency, $server);
                continue;
            }
            // Deleted since the order: keep the line with its stored IDs.
            $item = [
                'id' => 'wc-' . ($line->get_variation_id() ?: $line->get_product_id()),
                'name' => $this->name($line->get_name()),
                'content_type' => 'product',
                'quantity' => $quantity,
            ];
            if ($unit !== null) {
                $item['amount'] = $unit;
                $item['currency'] = $currency;
            }
            $items[] = $item;
        }

        /**
         * The purchase value sent to OpenAI. Defaults to the order total
         * the customer paid, including tax and shipping.
         *
         * @param float     $value
         * @param \WC_Order $order
         */
        $value = apply_filters('feedagentx_pixel_order_value', (float) $order->get_total(), $order);

        return $this->contents($this->minor($value, $currency), $currency, $items);
    }

    /** Integer minor units, or null when the amount or currency is unusable. */
    public function minor(mixed $amount, string $currency): ?int
    {
        if (! is_numeric($amount) || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }
        $value = (float) $amount;
        if ($value < 0 || ! is_finite($value)) {
            return null;
        }
        try {
            return $this->money->to_minor_units(sprintf('%.6F', $value), $currency);
        } catch (\Throwable) {
            return null;
        }
    }

    private function contents(?int $amount, string $currency, array $items): array
    {
        $data = ['type' => 'contents'];
        if ($amount !== null) {
            $data['amount'] = $amount;
            $data['currency'] = $currency;
        }
        if ($items !== []) {
            $data['contents'] = $items;
        }
        return $data;
    }

    private function item(\WC_Product $product, int $quantity, ?int $unit, string $currency, bool $server): array
    {
        $item = [
            'id' => StableId::for_product($product),
            'name' => $this->name($product->get_name()),
            'content_type' => 'product',
            'quantity' => $quantity,
        ];
        if ($unit !== null) {
            $item['amount'] = $unit;
            $item['currency'] = $currency;
        }
        if ($server && $product->get_parent_id()) {
            $item['group_id'] = 'wc-' . $product->get_parent_id();
            $variant = $this->variant_dict($product);
            if ($variant !== []) {
                $item['variant_dict'] = $variant;
            }
        }
        return $item;
    }

    /** @return array<string, string> e.g. {"size": "medium", "color": "blue"} */
    private function variant_dict(\WC_Product $product): array
    {
        if (! $product instanceof \WC_Product_Variation) {
            return [];
        }
        $out = [];
        foreach ($product->get_attributes() as $key => $value) {
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }
            $label = str_starts_with((string) $key, 'pa_') ? substr((string) $key, 3) : (string) $key;
            $out[$label] = (string) $value;
        }
        return $out;
    }

    private function name(string $name): string
    {
        $name = trim(wp_strip_all_tags($name));
        return function_exists('mb_substr') ? mb_substr($name, 0, self::NAME_LIMIT) : substr($name, 0, self::NAME_LIMIT);
    }
}
