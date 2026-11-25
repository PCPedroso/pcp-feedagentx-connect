<?php

namespace FeedAgentX\Hooks;

defined('ABSPATH') || exit;

use FeedAgentX\Infra\ChangeLogRepository;
use FeedAgentX\Infra\Logger;

/**
 * Subscribes to WooCommerce product lifecycle events and writes them to the
 * change log so SaaS can pick them up via /feed/changes.
 *
 * Each handler short-circuits if the post isn't a product/variation, since
 * generic post-meta hooks fire for every post type.
 */
final class WooEvents
{
    private const PRICE_META_KEYS = ['_price', '_regular_price', '_sale_price'];
    private const STOCK_META_KEYS = ['_stock', '_stock_status', '_manage_stock', '_backorders'];
    private const IMAGE_META_KEYS = ['_thumbnail_id', '_product_image_gallery'];

    public function __construct(
        private readonly ChangeLogRepository $change_log,
    ) {
    }

    public function register(): void
    {
        // Create / update — covers admin save, REST, CLI.
        add_action('woocommerce_new_product', [$this, 'on_product_created'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'on_product_updated'], 10, 1);
        add_action('woocommerce_new_product_variation', [$this, 'on_variation_changed'], 10, 1);
        add_action('woocommerce_update_product_variation', [$this, 'on_variation_changed'], 10, 1);

        // Trash / delete.
        add_action('wp_trash_post', [$this, 'on_post_trash'], 10, 1);
        add_action('before_delete_post', [$this, 'on_post_delete'], 10, 1);
        add_action('woocommerce_delete_product_variation', [$this, 'on_variation_deleted'], 10, 1);
        add_action('woocommerce_trash_product_variation', [$this, 'on_variation_deleted'], 10, 1);

        // Stock — explicit hooks fire on quantity / status change.
        add_action('woocommerce_product_set_stock', [$this, 'on_stock_change'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'on_stock_change'], 10, 1);
        add_action('woocommerce_product_set_stock_status', [$this, 'on_stock_status_change'], 10, 3);
        add_action('woocommerce_variation_set_stock_status', [$this, 'on_stock_status_change'], 10, 3);

        // Catch-all for meta key writes (price, image, stock).
        add_action('updated_post_meta', [$this, 'on_post_meta_change'], 10, 4);
        add_action('added_post_meta', [$this, 'on_post_meta_change'], 10, 4);
        add_action('deleted_post_meta', [$this, 'on_post_meta_deleted'], 10, 4);
        add_action('transition_post_status', [$this, 'on_status_transition'], 10, 3);
    }

    public function on_product_created(int $product_id): void
    {
        $this->change_log->log($product_id, ChangeLogRepository::CHANGE_CREATED);
    }

    public function on_product_updated(int $product_id): void
    {
        $this->change_log->log($product_id, ChangeLogRepository::CHANGE_UPDATED);
    }

    public function on_variation_changed(int $variation_id): void
    {
        $this->change_log->log($variation_id, ChangeLogRepository::CHANGE_UPDATED);
    }

    public function on_post_trash(int $post_id): void
    {
        if (! $this->is_product_post($post_id)) {
            return;
        }
        $this->change_log->log($post_id, ChangeLogRepository::CHANGE_DELETED);
    }

    public function on_post_delete(int $post_id): void
    {
        if (! $this->is_product_post($post_id)) {
            return;
        }
        $this->change_log->log($post_id, ChangeLogRepository::CHANGE_DELETED);
    }

    public function on_variation_deleted(int $variation_id): void
    {
        $this->change_log->log($variation_id, ChangeLogRepository::CHANGE_DELETED);
    }

    /**
     * @param \WC_Product|int $product_or_id
     */
    public function on_stock_change($product_or_id): void
    {
        $product_id = $product_or_id instanceof \WC_Product
            ? $product_or_id->get_id()
            : (int) $product_or_id;

        if ($product_id <= 0) {
            return;
        }
        $this->change_log->log($product_id, ChangeLogRepository::CHANGE_STOCK);
    }

    /**
     * @param int    $product_id
     * @param string $stock_status
     * @param mixed  $product
     */
    public function on_stock_status_change(int $product_id, string $stock_status, $product): void
    {
        $this->change_log->log($product_id, ChangeLogRepository::CHANGE_STOCK);
    }

    /**
     * Catch-all for meta writes that should trigger a feed refresh.
     */
    public function on_post_meta_change(int $meta_id, int $object_id, string $meta_key, mixed $_meta_value): void
    {
        if (! $this->is_product_post($object_id)) {
            return;
        }

        if (in_array($meta_key, ['_faxc_eligible_ads', '_faxc_eligible_search', '_faxc_eligible_checkout'], true)) {
            $this->change_log->log($object_id, ChangeLogRepository::CHANGE_UPDATED);
            return;
        }
        if (in_array($meta_key, self::PRICE_META_KEYS, true)) {
            $this->change_log->log($object_id, ChangeLogRepository::CHANGE_PRICE);
            return;
        }

        if (in_array($meta_key, self::STOCK_META_KEYS, true)) {
            $this->change_log->log($object_id, ChangeLogRepository::CHANGE_STOCK);
            return;
        }

        if (in_array($meta_key, self::IMAGE_META_KEYS, true)) {
            $this->change_log->log($object_id, ChangeLogRepository::CHANGE_IMAGE);
        }
    }

    public function on_post_meta_deleted($meta_ids, int $object_id, string $meta_key, mixed $value): void
    {
        $this->on_post_meta_change(0, $object_id, $meta_key, $value);
    }

    public function on_status_transition(string $new, string $old, $post): void
    {
        if ($new !== $old && $this->is_product_post((int) $post->ID)) $this->change_log->log((int) $post->ID, ChangeLogRepository::CHANGE_UPDATED);
    }

    private function is_product_post(int $post_id): bool
    {
        $type = get_post_type($post_id);
        return $type === 'product' || $type === 'product_variation';
    }
}
