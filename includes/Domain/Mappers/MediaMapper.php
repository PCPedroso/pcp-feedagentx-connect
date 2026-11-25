<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Media;

/**
 * Builds ACP Media[] arrays from WooCommerce attachment IDs.
 *
 * For variations, falls back to the parent's image when the variation has none.
 * Always emits the primary (main) image first.
 */
final class MediaMapper
{
    /**
     * @return Media[]
     */
    public function from_product(\WC_Product $product): array
    {
        $main_id = $product->get_image_id();

        // Variations: fall back to parent's main image if missing.
        if (! $main_id && $product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $main_id = $parent->get_image_id();
            }
        }

        $gallery_ids = method_exists($product, 'get_gallery_image_ids')
            ? $product->get_gallery_image_ids()
            : [];

        // For variations, also append the parent gallery to maximize media coverage.
        if ($product instanceof \WC_Product_Variation) {
            $parent = wc_get_product($product->get_parent_id());
            if ($parent instanceof \WC_Product) {
                $parent_gallery = $parent->get_gallery_image_ids();
                $gallery_ids = array_values(array_unique(array_merge($gallery_ids, $parent_gallery)));
            }
        }

        $media = [];
        $seen = [];

        if ($main_id) {
            $entry = $this->build_image_entry((int) $main_id);
            if ($entry !== null) {
                $media[] = $entry;
                $seen[$main_id] = true;
            }
        }

        foreach ($gallery_ids as $id) {
            $id = (int) $id;
            if ($id === 0 || isset($seen[$id])) {
                continue;
            }
            $entry = $this->build_image_entry($id);
            if ($entry !== null) {
                $media[] = $entry;
                $seen[$id] = true;
            }
        }

        return $media;
    }

    private function build_image_entry(int $attachment_id): ?Media
    {
        $url = wp_get_attachment_url($attachment_id);
        if (! $url) {
            return null;
        }

        $alt = (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        $meta = wp_get_attachment_metadata($attachment_id);
        $width = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : null;
        $height = is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : null;

        return new Media(
            type: Media::TYPE_IMAGE,
            url: esc_url_raw($url),
            alt_text: $alt !== '' ? $alt : null,
            width: $width,
            height: $height,
        );
    }
}
