<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * ACP Variant object — a purchasable configuration of a Product.
 */
final class Variant
{
    /**
     * @param Barcode[]                    $barcodes
     * @param Category[]                   $categories
     * @param string[]                     $condition
     * @param VariantOption[]              $variant_options
     * @param Media[]                      $media
     * @param array{value:string, unit:string}|null $weight
     * @param array<string, string>|null   $dimensions length/width/height (present members only) + unit
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?Description $description = null,
        public readonly ?string $url = null,
        public readonly array $barcodes = [],
        public readonly ?Price $price = null,
        public readonly ?Price $list_price = null,
        public readonly ?UnitPrice $unit_price = null,
        public readonly ?Availability $availability = null,
        public readonly array $categories = [],
        public readonly array $condition = [],
        public readonly array $variant_options = [],
        public readonly array $media = [],
        public readonly ?Seller $seller = null,
        public readonly ?string $gtin = null,
        public readonly ?string $mpn = null,
        public readonly ?string $sale_price_start_date = null,
        public readonly ?string $sale_price_end_date = null,
        public readonly ?string $availability_date = null,
        public readonly ?array $weight = null,
        public readonly ?array $dimensions = null,
        public readonly ?bool $is_digital = null,
        public readonly ?string $source_id = null,
    ) {
    }

    public function to_array(): array
    {
        $out = [
            'id' => $this->id,
            'title' => $this->title,
        ];

        if ($this->source_id !== null) $out['source_id'] = $this->source_id;

        if ($this->description !== null && ! $this->description->is_empty()) {
            $out['description'] = $this->description->to_array();
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        if (! empty($this->barcodes)) {
            $out['barcodes'] = array_map(static fn (Barcode $b): array => $b->to_array(), $this->barcodes);
        }
        if ($this->gtin !== null) {
            $out['gtin'] = $this->gtin;
        }
        if ($this->mpn !== null) {
            $out['mpn'] = $this->mpn;
        }
        if ($this->price !== null) {
            $out['price'] = $this->price->to_array();
        }
        if ($this->list_price !== null) {
            $out['list_price'] = $this->list_price->to_array();
        }
        if ($this->sale_price_start_date !== null) {
            $out['sale_price_start_date'] = $this->sale_price_start_date;
        }
        if ($this->sale_price_end_date !== null) {
            $out['sale_price_end_date'] = $this->sale_price_end_date;
        }
        if ($this->unit_price !== null) {
            $out['unit_price'] = $this->unit_price->to_array();
        }
        if ($this->availability !== null) {
            $out['availability'] = $this->availability->to_array();
        }
        if ($this->availability_date !== null) {
            $out['availability_date'] = $this->availability_date;
        }
        if (! empty($this->categories)) {
            $out['categories'] = array_map(static fn (Category $c): array => $c->to_array(), $this->categories);
        }
        if (! empty($this->condition)) {
            $out['condition'] = array_values($this->condition);
        }
        if (! empty($this->variant_options)) {
            $out['variant_options'] = array_map(static fn (VariantOption $o): array => $o->to_array(), $this->variant_options);
        }
        if (! empty($this->media)) {
            $out['media'] = array_map(static fn (Media $m): array => $m->to_array(), $this->media);
        }
        if ($this->weight !== null) {
            $out['weight'] = $this->weight;
        }
        if ($this->dimensions !== null) {
            $out['dimensions'] = $this->dimensions;
        }
        if ($this->is_digital !== null) {
            $out['is_digital'] = $this->is_digital;
        }
        if ($this->seller !== null) {
            $out['seller'] = $this->seller->to_array();
        }

        return $out;
    }
}
