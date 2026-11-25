<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Product;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Builds an ACP Product from a WooCommerce product, expanding variations.
 *
 * Product-type handling:
 *   - simple   → 1 product with 1 variant
 *   - variable → 1 product with N variants (one per non-disabled variation)
 *   - grouped  → skipped at this layer (FeedService expands children)
 *   - external → 1 variant; SaaS may downgrade to search-only
 *   - any other → 1 variant best-effort
 */
final class ProductMapper
{
    private const META_ELIGIBLE_SEARCH = '_faxc_eligible_search';
    private const META_ELIGIBLE_CHECKOUT = '_faxc_eligible_checkout';

    public function __construct(
        private readonly VariantMapper $variant_mapper,
        private readonly DescriptionMapper $description_mapper,
        private readonly MediaMapper $media_mapper,
        private readonly BrandResolver $brand_resolver,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Returns null when the product type isn't independently mappable
     * (e.g. grouped) — the caller decides what to do.
     */
    public function map(\WC_Product $product): ?Product
    {
        if ($product->is_type('grouped')) {
            // Grouped products are containers; their children are mapped separately.
            return null;
        }

        if ($product->is_type('variable')) {
            return $this->map_variable($product);
        }

        // simple, external, virtual, downloadable, third-party types fall through here.
        return $this->map_single($product);
    }

    private function map_variable(\WC_Product $product): ?Product
    {
        if (! $product instanceof \WC_Product_Variable) {
            // Some 3rd-party plugins lie about is_type('variable'); treat as single.
            return $this->map_single($product);
        }

        $children = $product->get_children();
        $variants = [];

        foreach ($children as $variation_id) {
            $variation = wc_get_product((int) $variation_id);
            if (! ($variation instanceof \WC_Product_Variation)) {
                continue;
            }
            if ($variation->get_status() !== 'publish') {
                continue;
            }
            $variants[] = $this->variant_mapper->from_product($variation);
        }

        return $this->build_product($product, $variants);
    }

    private function map_single(\WC_Product $product): Product
    {
        return $this->build_product($product, [$this->variant_mapper->from_product($product)]);
    }

    /**
     * @param \FeedAgentX\Domain\Schema\Variant[] $variants
     */
    private function build_product(\WC_Product $product, array $variants): Product
    {
        [$eligible_search, $eligible_checkout] = $this->resolve_eligibility($product);

        // Hooks from other plugins run inside these reads; see MappingGuard.
        return new Product(
            id: $this->resolve_product_id($product),
            variants: $variants,
            title: MappingGuard::run('title', static fn () => $product->get_name() ?: null, static fn () => $product->get_name('edit') ?: null),
            description: MappingGuard::run('description', fn () => $this->description_mapper->from_product($product), static fn () => null),
            url: MappingGuard::run('url', static fn () => $product->get_permalink() ?: null, static fn () => null),
            media: MappingGuard::run('media', fn () => $this->media_mapper->from_product($product), static fn () => []),
            brand: MappingGuard::run('brand', fn () => $this->brand_resolver->resolve($product), static fn () => ''),
            is_eligible_search: $eligible_search,
            is_eligible_checkout: $eligible_checkout,
            // Canonical Ads flag, independent of search eligibility.
            is_ads_eligible: $this->resolve_eligibility_flag($product, '_faxc_eligible_ads', $this->settings->is_ads_enabled()),
            ads_metadata: $this->settings->get_ads_metadata() ?: null,
            listing_has_variations: $product->is_type('variable') && ! (count($variants) === 1 && $variants[0]->source_id === (string) $product->get_id()),
            source_id: (string) $product->get_id(),
            legacy_id: $this->legacy_id($product),
            eligibility_overrides: $this->explicit_overrides($product),
        );
    }

    /**
     * Eligibility flags: global settings default, overridable per product via
     * post meta ('' = inherit, '0'/'1' = override). Checkout is forced false
     * when search is false — the spec forbids checkout-only listings.
     *
     * @return array{0:bool, 1:bool} [search, checkout]
     */
    private function resolve_eligibility(\WC_Product $product): array
    {
        $search = $this->resolve_eligibility_flag($product, self::META_ELIGIBLE_SEARCH, $this->settings->is_search_enabled());
        $checkout = $this->resolve_eligibility_flag($product, self::META_ELIGIBLE_CHECKOUT, $this->settings->is_checkout_enabled());

        if (! $search) {
            $checkout = false;
        }

        return [$search, $checkout];
    }

    private function resolve_eligibility_flag(\WC_Product $product, string $meta_key, bool $default): bool
    {
        $flag = $this->flag_meta($product, $meta_key);
        return $flag === '' ? $default : $flag === '1';
    }

    /**
     * A per-product flag as '1', '0' or '' (inherit the store setting).
     *
     * Anything else stored under the key is no override. Neither is a read
     * that another plugin's hook made fail: the product keeps the store
     * setting and the failure is reported (see MappingGuard).
     */
    private function flag_meta(\WC_Product $product, string $meta_key): string
    {
        $raw = MappingGuard::run('eligibility', static fn () => $product->get_meta($meta_key), static fn () => '');
        $value = is_string($raw) || is_int($raw) ? (string) $raw : '';
        return $value === '0' || $value === '1' ? $value : '';
    }

    private function legacy_id(\WC_Product $product): string
    {
        $sku = (string) MappingGuard::run('sku', static fn () => $product->get_sku(), static fn () => $product->get_sku('edit'));
        return $sku !== '' ? $sku : (string) $product->get_id();
    }

    /**
     * Stable Product ID — used as the parent identifier across all variants.
     * Prefers parent SKU; falls back to post ID.
     */
    public function resolve_product_id(\WC_Product $product): string
    {
        return StableId::for_product($product);
    }

    private function explicit_overrides(\WC_Product $product): array
    {
        $out = [];
        foreach (['search', 'checkout', 'ads'] as $field) {
            $flag = $this->flag_meta($product, '_faxc_eligible_' . $field);
            $out[$field] = $flag === '' ? null : $flag === '1';
        }
        return $out;
    }
}
