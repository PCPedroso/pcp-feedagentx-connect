<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Category
{
    public const TAXONOMY_MERCHANT = 'merchant';
    public const TAXONOMY_GOOGLE = 'google_product_category';
    public const TAXONOMY_SHOPIFY = 'shopify';

    public function __construct(
        public readonly string $value,
        public readonly ?string $taxonomy = null,
    ) {
    }

    public function to_array(): array
    {
        $out = ['value' => $this->value];
        if ($this->taxonomy !== null) {
            $out['taxonomy'] = $this->taxonomy;
        }
        return $out;
    }
}
