<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * ACP Product object — root of a single feed line (nested Product → Variants
 * wire format, enriched with the flat file-upload spec fields).
 */
final class Product
{
    /**
     * @param Variant[] $variants Required by spec; must be non-empty.
     * @param Media[]   $media    Product-level media (covers all variants).
     */
    public function __construct(
        public readonly string $id,
        public readonly array $variants,
        public readonly ?string $title = null,
        public readonly ?Description $description = null,
        public readonly ?string $url = null,
        public readonly array $media = [],
        public readonly ?string $brand = null,
        public readonly ?bool $is_eligible_search = null,
        public readonly ?bool $is_eligible_checkout = null,
        public readonly ?bool $is_ads_eligible = null,
        /** @var array<string, string>|null Optional ads_metadata object (flat spec). */
        public readonly ?array $ads_metadata = null,
        public readonly ?bool $listing_has_variations = null,
        public readonly ?string $source_id = null,
        public readonly ?string $legacy_id = null,
        public readonly ?array $eligibility_overrides = null,
    ) {
    }

    public function to_array(): array
    {
        $out = ['id' => $this->id];
        if ($this->source_id !== null) $out['source_id'] = $this->source_id;
        if ($this->legacy_id !== null) $out['legacy_id'] = $this->legacy_id;
        if ($this->eligibility_overrides !== null) $out['eligibility_overrides'] = $this->eligibility_overrides;

        if ($this->title !== null) {
            $out['title'] = $this->title;
        }
        if ($this->description !== null && ! $this->description->is_empty()) {
            $out['description'] = $this->description->to_array();
        }
        if ($this->url !== null) {
            $out['url'] = $this->url;
        }
        if ($this->brand !== null) {
            $out['brand'] = $this->brand;
        }
        if ($this->is_eligible_search !== null) {
            $out['is_eligible_search'] = $this->is_eligible_search;
        }
        if ($this->is_eligible_checkout !== null) {
            $out['is_eligible_checkout'] = $this->is_eligible_checkout;
        }
        if ($this->is_ads_eligible !== null) {
            $out['is_ads_eligible'] = $this->is_ads_eligible;
        }
        if (! empty($this->ads_metadata)) {
            $out['ads_metadata'] = $this->ads_metadata;
        }
        if ($this->listing_has_variations !== null) {
            $out['listing_has_variations'] = $this->listing_has_variations;
        }
        if (! empty($this->media)) {
            $out['media'] = array_map(static fn (Media $m): array => $m->to_array(), $this->media);
        }

        $out['variants'] = array_map(static fn (Variant $v): array => $v->to_array(), $this->variants);

        return $out;
    }
}
