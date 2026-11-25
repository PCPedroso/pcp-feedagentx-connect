<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Barcode;

/**
 * Reads barcode/identifier meta from WC products.
 *
 * Looks at common meta keys used in the wild:
 *   - WooCommerce 8.4+ native: _global_unique_id (GTIN/UPC/EAN/ISBN)
 *   - Custom: _gtin, _ean, _upc, _isbn, _mpn
 *
 * MPNs may fall back to parent meta; GTINs always identify the individual variant.
 */
final class BarcodeMapper
{
    private const GTIN_KEYS = ['_global_unique_id', '_gtin', '_ean', '_upc', '_isbn'];
    private const MPN_KEYS = ['_mpn', '_manufacturer_part_number'];

    /**
     * @return Barcode[]
     */
    public function from_product(\WC_Product $product): array
    {
        $codes = [];

        $gtin = $this->get_gtin($product);
        if ($gtin !== null) {
            $codes[] = new Barcode(type: Barcode::TYPE_GTIN, value: $gtin);
        }

        return $codes;
    }

    /**
     * Primary GTIN as a scalar — the flat-spec `gtin` field alongside `barcodes[]`.
     */
    public function get_gtin(\WC_Product $product): ?string
    {
        $gtin = $this->first_meta($product, self::GTIN_KEYS, false);
        return $gtin; // Preserve invalid source data so validation can report it.
    }

    public function get_mpn(\WC_Product $product): ?string
    {
        return $this->first_meta($product, self::MPN_KEYS);
    }

    private function first_meta(\WC_Product $product, array $keys, bool $inherit = true): ?string
    {
        foreach ($keys as $key) {
            // Modern WooCommerce owns this as an internal property; get_meta()
            // triggers a doing_it_wrong notice and can corrupt displayed REST JSON.
            $value = $key === '_global_unique_id' && method_exists($product, 'get_global_unique_id')
                ? $product->get_global_unique_id()
                : $product->get_meta($key);
            if (is_string($value) && $value !== '') {
                return trim($value);
            }
        }

        // Variations: fall back to parent meta.
        if ($inherit && $product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                foreach ($keys as $key) {
                    $value = $parent->get_meta($key);
                    if (is_string($value) && $value !== '') {
                        return trim($value);
                    }
                }
            }
        }

        return null;
    }

}
