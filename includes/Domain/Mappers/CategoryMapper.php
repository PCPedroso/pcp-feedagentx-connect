<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Category;

/**
 * Builds Category[] from WooCommerce product_cat taxonomy.
 *
 * One Category per assigned terminal category, value = full path
 * "Parent > Child > Leaf". Taxonomy is always "merchant" — SaaS may map to
 * google_product_category later.
 */
final class CategoryMapper
{
    /**
     * @return Category[]
     */
    public function from_product(\WC_Product $product): array
    {
        // Variations inherit categories from their parent.
        $product_id = $product instanceof \WC_Product_Variation
            ? $product->get_parent_id()
            : $product->get_id();

        $terms = get_the_terms($product_id, 'product_cat');
        if (is_wp_error($terms) || empty($terms)) {
            return [];
        }

        $paths = [];
        foreach ($terms as $term) {
            if (! ($term instanceof \WP_Term)) {
                continue;
            }
            $path = $this->build_path($term);
            if ($path !== '') {
                $paths[$path] = true; // dedupe
            }
        }

        $categories = [];
        foreach (array_keys($paths) as $path) {
            $categories[] = new Category(value: $path, taxonomy: Category::TAXONOMY_MERCHANT);
        }
        return $categories;
    }

    /**
     * Returns the deepest path for a single category as "Root > Child > Leaf".
     */
    private function build_path(\WP_Term $term): string
    {
        $path = [$term->name];
        $parent_id = $term->parent;

        $guard = 0;
        while ($parent_id && $guard < 50) {
            $parent = get_term($parent_id, 'product_cat');
            if (is_wp_error($parent) || ! ($parent instanceof \WP_Term)) {
                break;
            }
            array_unshift($path, $parent->name);
            $parent_id = $parent->parent;
            $guard++;
        }

        return implode(' > ', $path);
    }
}
