<?php

namespace FeedAgentX\Hooks;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ChangeLogRepository;

/**
 * Logs changes when a product's taxonomy assignments change (categories,
 * tags, brand taxonomy, custom attributes).
 *
 * `set_object_terms` fires whenever WooCommerce or admin updates a term set.
 */
final class TaxonomyEvents
{
    private const TRACKED_TAXONOMIES = [
        'product_cat',
        'product_tag',
        'product_brand',
        'pwb-brand',
        'yith_product_brand',
    ];

    public function __construct(
        private readonly ChangeLogRepository $change_log,
    ) {
    }

    public function register(): void
    {
        add_action('set_object_terms', [$this, 'on_set_object_terms'], 10, 6);
    }

    /**
     * @param int      $object_id
     * @param array    $terms
     * @param array    $tt_ids
     * @param string   $taxonomy
     * @param bool     $append
     * @param array    $old_tt_ids
     */
    public function on_set_object_terms(int $object_id, array $terms, array $tt_ids, string $taxonomy, bool $append, array $old_tt_ids): void
    {
        // Only react to product-related taxonomies and any pa_* attribute taxonomy.
        $is_attribute = str_starts_with($taxonomy, 'pa_');
        if (! $is_attribute && ! in_array($taxonomy, self::TRACKED_TAXONOMIES, true)) {
            return;
        }

        if (get_post_type($object_id) !== 'product' && get_post_type($object_id) !== 'product_variation') {
            return;
        }

        // Avoid noise: only log when the set actually differs.
        sort($tt_ids);
        sort($old_tt_ids);
        if ($tt_ids === $old_tt_ids) {
            return;
        }

        $this->change_log->log($object_id, ChangeLogRepository::CHANGE_TAXONOMY);
    }
}
