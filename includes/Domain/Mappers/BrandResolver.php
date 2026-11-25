<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\SettingsRepository;

/**
 * Resolves the brand name for a product, checking multiple sources in
 * priority order. The settings panel can pin a specific source or always
 * override with a fixed value.
 *
 * Priority (auto mode):
 *   1. SettingsRepository override (if set)
 *   2. Native WooCommerce Brands taxonomy (`product_brand`)
 *   3. Perfect WooCommerce Brands (`pwb-brand`)
 *   4. YITH Brands (`yith_product_brand`)
 *   5. Per-product `_brand` meta
 *   6. WooCommerce attribute `pa_brand`
 *   Site name is used only when explicitly selected.
 */
final class BrandResolver
{
    private const BRAND_TAXONOMIES = [
        'product_brand',         // WooCommerce Brands (native)
        'pwb-brand',             // Perfect WooCommerce Brands
        'yith_product_brand',    // YITH WooCommerce Brands Add-On
        'product_brands',        // Older variant
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    public function resolve(\WC_Product $product): string
    {
        $source = $this->settings->get_brand_source();
        $override = $this->settings->get_brand_override();

        if ($source === SettingsRepository::BRAND_SOURCE_OVERRIDE && $override !== null) {
            return $this->truncate($override);
        }

        // Auto mode tries each source until one yields a value.
        $candidates = match ($source) {
            SettingsRepository::BRAND_SOURCE_TAXONOMY => [$this->from_taxonomy(...)],
            SettingsRepository::BRAND_SOURCE_META => [$this->from_meta(...)],
            SettingsRepository::BRAND_SOURCE_ATTRIBUTE => [$this->from_attribute(...)],
            SettingsRepository::BRAND_SOURCE_SITE_NAME => [$this->from_site_name(...)],
            default => [
                $this->from_taxonomy(...),
                $this->from_meta(...),
                $this->from_attribute(...),
            ],
        };

        foreach ($candidates as $resolver) {
            $value = $resolver($product);
            if ($value !== null && $value !== '') {
                return $this->truncate($value);
            }
        }

        if ($override !== null && $override !== '') {
            return $this->truncate($override);
        }

        return ''; // A merchant name is a brand only when explicitly selected as the source.
    }

    private function from_taxonomy(\WC_Product $product): ?string
    {
        $product_id = $product instanceof \WC_Product_Variation
            ? $product->get_parent_id()
            : $product->get_id();

        foreach (self::BRAND_TAXONOMIES as $taxonomy) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_the_terms($product_id, $taxonomy);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            $first = $terms[0];
            if ($first instanceof \WP_Term && $first->name !== '') {
                return $first->name;
            }
        }
        return null;
    }

    private function from_meta(\WC_Product $product): ?string
    {
        foreach (['_brand', '_product_brand'] as $key) {
            $value = $product->get_meta($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if ($product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                return $this->from_meta($parent);
            }
        }

        return null;
    }

    private function from_attribute(\WC_Product $product): ?string
    {
        $value = $product->get_attribute('brand');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                return $this->from_attribute($parent);
            }
        }

        return null;
    }

    private function from_site_name(\WC_Product $product): ?string
    {
        $name = get_bloginfo('name');
        return is_string($name) && $name !== '' ? $name : null;
    }

    private function truncate(string $value): string
    {
        return trim($value);
    }
}
