<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Variant;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Builds an ACP Variant from a single WC product (simple) or variation.
 *
 * - Simple products are themselves a single variant of their own one-variant Product.
 * - Variations carry variant-specific price/stock/image/options; missing fields
 *   fall back to the parent (handled inside individual mappers).
 */
final class VariantMapper
{
    private const MAX_TITLE_LENGTH = 150;

    public function __construct(
        private readonly PriceMapper $price_mapper,
        private readonly DescriptionMapper $description_mapper,
        private readonly MediaMapper $media_mapper,
        private readonly AvailabilityMapper $availability_mapper,
        private readonly CategoryMapper $category_mapper,
        private readonly BarcodeMapper $barcode_mapper,
        private readonly VariantOptionMapper $variant_option_mapper,
        private readonly SellerMapper $seller_mapper,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function from_product(\WC_Product $product): Variant
    {
        // Each read below runs other plugins' and the theme's hooks; one
        // that throws costs only its own field (see MappingGuard).
        [$price, $list_price] = MappingGuard::run(
            'price',
            fn () => $this->displayed_prices($product),
            fn () => $this->stored_prices($product),
        );

        $variant_options = $product instanceof \WC_Product_Variation
            ? MappingGuard::run('variant_options', fn () => $this->variant_option_mapper->from_variation($product), static fn () => [])
            : [];

        return new Variant(
            id: $this->resolve_id($product),
            source_id: (string) $product->get_id(),
            title: MappingGuard::run('title', fn () => $this->build_title($product), fn () => $this->stored_title($product)),
            description: MappingGuard::run('description', fn () => $this->description_mapper->from_product($product), static fn () => null),
            url: MappingGuard::run('url', fn () => $this->resolve_url($product), static fn () => null),
            barcodes: MappingGuard::run('gtin', fn () => $this->barcode_mapper->from_product($product), static fn () => []),
            price: $price,
            list_price: $list_price,
            unit_price: null,
            availability: MappingGuard::run('availability', fn () => $this->availability_mapper->from_product($product), fn () => $this->availability_mapper->from_stored($product)),
            categories: MappingGuard::run('categories', fn () => $this->category_mapper->from_product($product), static fn () => []),
            condition: [$this->settings->get_default_condition()],
            variant_options: $variant_options,
            media: MappingGuard::run('media', fn () => $this->media_mapper->from_product($product), static fn () => []),
            seller: MappingGuard::run('seller', fn () => $this->seller_mapper->build(), static fn () => null),
            gtin: MappingGuard::run('gtin', fn () => $this->barcode_mapper->get_gtin($product), static fn () => null),
            mpn: MappingGuard::run('mpn', fn () => $this->barcode_mapper->get_mpn($product), static fn () => null),
            sale_price_start_date: MappingGuard::run('sale_dates', fn () => $this->to_iso_date($product->get_date_on_sale_from()), static fn () => null),
            sale_price_end_date: MappingGuard::run('sale_dates', fn () => $this->to_iso_date($product->get_date_on_sale_to()), static fn () => null),
            availability_date: MappingGuard::run('availability_date', fn () => $this->availability_mapper->resolve_pre_order_date($product), static fn () => null),
            weight: MappingGuard::run('weight', fn () => $this->build_weight($product), static fn () => null),
            dimensions: MappingGuard::run('dimensions', fn () => $this->build_dimensions($product), static fn () => null),
            is_digital: MappingGuard::run('is_digital', fn () => $product->is_virtual() || $product->is_downloadable(), static fn () => null),
        );
    }

    /**
     * The price a shopper sees: ACP `price` = currently active price,
     * `list_price` = pre-discount reference.
     *
     * @return array{0: ?\FeedAgentX\Domain\Schema\Price, 1: ?\FeedAgentX\Domain\Schema\Price}
     */
    private function displayed_prices(\WC_Product $product): array
    {
        $currency = get_woocommerce_currency();

        $regular = $product->get_regular_price();
        $effective = $product->get_price(); // sale or regular, depending on schedule

        $amount = $effective !== '' ? $effective : $regular;
        $display_amount = $amount !== '' && function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product, ['price' => $amount]) : $amount;
        $price = $this->price_mapper->from_decimal($display_amount, $currency);
        $list_price = null;
        if ($product->is_on_sale() && (float) $effective > 0 && (float) $effective < (float) $regular) {
            $display_regular = function_exists('wc_get_price_to_display') ? wc_get_price_to_display($product, ['price' => $regular]) : $regular;
            $list_price = $this->price_mapper->from_decimal($display_regular, $currency);
        }

        return [$price, $list_price];
    }

    /**
     * The prices saved on the product, without other plugins' price hooks
     * (dynamic discounts, currency switchers). Used only when those fail.
     * `_price` already follows a scheduled sale.
     *
     * @return array{0: ?\FeedAgentX\Domain\Schema\Price, 1: ?\FeedAgentX\Domain\Schema\Price}
     */
    private function stored_prices(\WC_Product $product): array
    {
        $currency = (string) get_option('woocommerce_currency', 'USD');
        $regular = (string) $product->get_regular_price('edit');
        $sale = (string) $product->get_sale_price('edit');
        $amount = (string) $product->get_price('edit');
        if ($amount === '') {
            $amount = $regular;
        }

        $price = $this->price_mapper->from_decimal($this->stored_display_amount($product, $amount), $currency);
        $list_price = null;
        if ($sale !== '' && $amount === $sale && (float) $sale > 0 && (float) $sale < (float) $regular) {
            $list_price = $this->price_mapper->from_decimal($this->stored_display_amount($product, $regular), $currency);
        }

        return [$price, $list_price];
    }

    /** The store's tax display for a stored amount; the amount itself if that fails too. */
    private function stored_display_amount(\WC_Product $product, string $amount): string|float
    {
        if ($amount === '' || ! function_exists('wc_get_price_to_display')) {
            return $amount;
        }
        try {
            return wc_get_price_to_display($product, ['price' => $amount]);
        } catch (\Throwable) {
            return $amount;
        }
    }

    private function stored_title(\WC_Product $product): string
    {
        $title = (string) $product->get_name('edit');
        return $title !== '' ? $title : 'Untitled Product';
    }

    /**
     * Stable variant ID. Prefers SKU when available; otherwise uses post ID.
     */
    public function resolve_id(\WC_Product $product): string
    {
        return StableId::for_product($product);
    }

    private function resolve_url(\WC_Product $product): ?string
    {
        $url = $product->get_permalink();
        return is_string($url) && $url !== '' ? $url : null;
    }

    private function to_iso_date(?\WC_DateTime $date): ?string
    {
        return $date instanceof \WC_DateTime ? $date->date('Y-m-d') : null;
    }

    /**
     * @return array{value:string, unit:string}|null Null when no weight is set.
     */
    private function build_weight(\WC_Product $product): ?array
    {
        $value = $product->get_weight();
        if (! is_string($value) || $value === '') {
            return null;
        }
        return [
            'value' => $value,
            'unit' => (string) get_option('woocommerce_weight_unit', 'kg'),
        ];
    }

    /**
     * Dimensions object per the flat spec — empty members omitted; the whole
     * object omitted when no dimension is set.
     *
     * @return array<string, string>|null
     */
    private function build_dimensions(\WC_Product $product): ?array
    {
        $members = [
            'length' => $product->get_length(),
            'width' => $product->get_width(),
            'height' => $product->get_height(),
        ];

        $out = [];
        foreach ($members as $key => $value) {
            if (is_string($value) && $value !== '') {
                $out[$key] = $value;
            }
        }
        if (empty($out)) {
            return null;
        }

        $out['unit'] = (string) get_option('woocommerce_dimension_unit', 'cm');
        return $out;
    }

    private function build_title(\WC_Product $product): string
    {
        $title = $product->get_name();

        // Variations may have an empty name — derive from variant attributes.
        if (($title === null || $title === '') && $product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            $parent_name = $parent instanceof \WC_Product ? $parent->get_name() : '';
            $attrs = wc_get_formatted_variation($product, true);
            $title = trim($parent_name . ($attrs !== '' ? ' — ' . $attrs : ''));
        }

        if (! is_string($title) || $title === '') {
            $title = 'Untitled Product';
        }

        return $title;
    }
}
