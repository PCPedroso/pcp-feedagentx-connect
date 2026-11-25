<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\VariantOption;

/**
 * Reads a WooCommerce variation's selected attributes and emits VariantOption[].
 *
 * WC stores variation attributes as `attribute_pa_color => "blue"` (taxonomy)
 * or `attribute_color => "Blue"` (custom string attribute). For taxonomy
 * attributes, we resolve the slug to the human-readable term name.
 *
 * Empty values mean "Any" — these are skipped.
 */
final class VariantOptionMapper
{
    /**
     * @return VariantOption[]
     */
    public function from_variation(\WC_Product_Variation $variation): array
    {
        $attributes = $variation->get_attributes();
        if (empty($attributes)) {
            return [];
        }

        $options = [];
        foreach ($attributes as $key => $value) {
            if ($value === '' || $value === null) {
                // Keep the wildcard visible so validation asks for a concrete selection.
                $options[] = new VariantOption(name: $this->extract_attribute_name($key), value: '');
                continue;
            }

            $name = $this->extract_attribute_name($key);
            $resolved = $this->resolve_value($key, (string) $value);

            if ($name === '' || $resolved === '') {
                continue;
            }

            $options[] = new VariantOption(name: $name, value: $resolved);
        }

        return $options;
    }

    private function extract_attribute_name(string $attr_key): string
    {
        // attribute_pa_color → color, attribute_color → color
        $key = $attr_key;
        if (str_starts_with($key, 'pa_')) {
            $key = substr($key, 3);
        }

        // For taxonomy attributes, fetch the human label.
        if (taxonomy_exists('pa_' . $key)) {
            $label = wc_attribute_label('pa_' . $key);
            if (is_string($label) && $label !== '') {
                return $this->normalize_name($label);
            }
        }

        return $this->normalize_name($key);
    }

    private function resolve_value(string $attr_key, string $raw_value): string
    {
        // Taxonomy attributes store slugs — resolve to term name.
        $taxonomy = str_starts_with($attr_key, 'pa_') ? $attr_key : 'pa_' . $attr_key;
        if (taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $raw_value, $taxonomy);
            if ($term instanceof \WP_Term) {
                return $term->name;
            }
        }

        return trim($raw_value);
    }

    private function normalize_name(string $name): string
    {
        $name = str_replace(['_', '-'], ' ', $name);
        $name = trim($name);
        return strtolower($name);
    }
}
