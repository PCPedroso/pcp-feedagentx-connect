<?php

namespace FeedAgentX\Services;

defined('ABSPATH') || exit;

// Domain exceptions carry structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use FeedAgentX\Domain\Mappers\MappingGuard;
use FeedAgentX\Domain\Mappers\ProductMapper;
use FeedAgentX\Domain\Mappers\SellerMapper;
use FeedAgentX\Domain\Schema\Header;
use FeedAgentX\Domain\Schema\Product;
use FeedAgentX\Domain\Validation\ProductValidator;
use FeedAgentX\Infra\ErrorDiagnostics;
use FeedAgentX\Infra\Exceptions\NotFoundException;
use FeedAgentX\Infra\Exceptions\ProductMappingException;
use FeedAgentX\Infra\Logger;
use FeedAgentX\Infra\ProductRepository;
use FeedAgentX\Infra\SettingsRepository;

/**
 * High-level feed operations: full feed pagination + single-item lookup.
 */
final class FeedService
{
    private bool $storefront_tried = false;
    private bool $storefront_loaded = false;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductMapper $mapper,
        private readonly ProductValidator $validator,
        private readonly SettingsRepository $settings,
        private readonly SellerMapper $seller_mapper,
    ) {
    }

    /**
     * Returns a single page of products.
     *
     * @return array{
     *   header: array,
     *   items: array<int, array>,
     *   page_size: int,
     *   count: int,
     *   total: int,
     *   has_more: bool,
     *   next_cursor: ?int,
     *   validation_summary: array{errors:int, warnings:int},
     * }
     */
    public function get_full_feed_page(?int $after_id, int $per_page): array
    {
        $batch = $this->products->fetch_ids_after($after_id, $per_page);
        $items = [];
        $errors = 0;
        $warnings = 0;

        // Store-level context (seller block, countries, eligibility) is shared
        // by every row on the page — build once, reuse for validation.
        $header = $this->build_header();
        $store_context = $header->to_array();

        $degraded = [];
        foreach ($batch['ids'] as $product_id) {
            $wc_product = wc_get_product($product_id);
            if (! ($wc_product instanceof \WC_Product)) {
                continue;
            }

            [$product, $failures] = $this->map_product($wc_product);
            $degraded = array_merge($degraded, $failures);

            if ($product === null) {
                // grouped products etc.
                continue;
            }

            $validation = $this->validator->validate($product, $store_context);
            $errors += count($validation->get_errors());
            $warnings += count($validation->get_warnings());

            $entry = $product->to_array();
            // Surface validation issues in the response so SaaS can show them
            // directly without re-validating.
            if (! $validation->is_valid() || ! empty($validation->get_warnings())) {
                $entry['_validation'] = $validation->to_array();
            }

            $items[] = $entry;
        }

        $page = [
            'header' => $store_context,
            'items' => $items,
            'page_size' => $per_page,
            'count' => count($items),
            'total' => $batch['total'],
            'has_more' => $batch['has_more'],
            'next_cursor' => $batch['next_cursor'],
            'validation_summary' => [
                'errors' => $errors,
                'warnings' => $warnings,
            ],
        ];
        if (! empty($degraded)) {
            $page['degraded'] = $this->summarise($degraded);
        }
        if ($this->storefront_loaded) {
            $page['storefront_context'] = true;
        }
        return $page;
    }

    /**
     * @throws NotFoundException
     */
    public function get_item(int $product_id): array
    {
        $wc_product = $this->products->get_product($product_id);
        if (! $wc_product) {
            throw new NotFoundException('Product not found.', ['product_id' => $product_id]);
        }

        [$product, $failures] = $this->map_product($wc_product);
        if ($product === null) {
            throw new NotFoundException('Product not mappable (e.g. grouped product).', [
                'product_id' => $product_id,
            ]);
        }

        $header = $this->build_header();
        $store_context = $header->to_array();

        $validation = $this->validator->validate($product, $store_context);
        $entry = $product->to_array();
        if (! $validation->is_valid() || ! empty($validation->get_warnings())) {
            $entry['_validation'] = $validation->to_array();
        }

        $out = [
            'header' => $store_context,
            'item' => $entry,
        ];
        if (! empty($failures)) {
            $out['degraded'] = $this->summarise($failures);
        }
        if ($this->storefront_loaded) {
            $out['storefront_context'] = true;
        }
        return $out;
    }

    /**
     * Maps one product, keeping other plugins' failures from stopping the feed.
     *
     * Fields whose hooks throw fall back inside the mappers (MappingGuard).
     * Many pricing and cart plugins assume a storefront visit, where
     * WooCommerce has a session, a customer and a cart; a REST request has
     * none. So when anything failed, the product is read once more with
     * that context loaded, as WooCommerce's own Store API does for carts.
     *
     * @return array{0: ?Product, 1: array<int, array<string, mixed>>} The product and the fields that fell back.
     * @throws ProductMappingException when the product cannot be read at all.
     */
    private function map_product(\WC_Product $wc_product): array
    {
        $product_id = $wc_product->get_id();
        [$product, $failures, $error] = $this->attempt($wc_product);

        if (($error !== null || ! empty($failures)) && $this->load_storefront_context()) {
            $fresh = wc_get_product($product_id);
            if ($fresh instanceof \WC_Product) {
                [$product, $failures, $error] = $this->attempt($fresh);
            }
        }

        if ($error !== null) {
            Logger::error('Product could not be read', ['product_id' => $product_id] + ErrorDiagnostics::describe($error));
            throw new ProductMappingException($product_id, $error);
        }

        foreach ($failures as &$failure) {
            $failure = ['product_id' => $product_id] + $failure;
        }
        unset($failure);
        return [$product, $failures];
    }

    /** @return array{0: ?Product, 1: array<int, array<string, mixed>>, 2: ?\Throwable} */
    private function attempt(\WC_Product $wc_product): array
    {
        MappingGuard::reset();
        try {
            $product = $this->mapper->map($wc_product);
            return [$product, MappingGuard::take(), null];
        } catch (\Throwable $e) {
            return [null, MappingGuard::take(), $e];
        }
    }

    /**
     * Gives WooCommerce the session, customer and cart it has on a
     * storefront visit, once per request. Nothing is saved: without the
     * shopper's session cookie WooCommerce writes no session and sets no
     * cookie.
     *
     * @return bool Whether the context was loaded now (a retry may help).
     */
    private function load_storefront_context(): bool
    {
        if ($this->storefront_tried || ! function_exists('WC') || ! function_exists('wc_load_cart')) {
            return false;
        }
        $this->storefront_tried = true;

        $wc = \WC();
        if ($wc->session !== null && $wc->cart !== null && $wc->customer !== null) {
            return false;
        }
        try {
            wc_load_cart();
        } catch (\Throwable $e) {
            Logger::warning('Could not load the WooCommerce storefront context', ErrorDiagnostics::describe($e));
            return false;
        }
        Logger::info('Loaded the WooCommerce storefront context after another component failed without it.');
        $this->storefront_loaded = true;
        return true;
    }

    /**
     * One entry per failing field and source, with the products it hit
     * (first few) — enough to name the conflicting plugin.
     *
     * @param array<int, array<string, mixed>> $failures
     * @return array<int, array<string, mixed>>
     */
    private function summarise(array $failures): array
    {
        $out = [];
        foreach ($failures as $failure) {
            $key = $failure['field'] . '|' . $failure['source'] . '|' . $failure['exception'];
            if (! isset($out[$key])) {
                $out[$key] = [
                    'field' => $failure['field'],
                    'source' => $failure['source'],
                    'exception' => $failure['exception'],
                    'message' => $failure['message'],
                    'at' => $failure['at'],
                    'products' => 0,
                    'product_ids' => [],
                ];
            }
            $out[$key]['products']++;
            if (count($out[$key]['product_ids']) < 5 && ! in_array($failure['product_id'], $out[$key]['product_ids'], true)) {
                $out[$key]['product_ids'][] = $failure['product_id'];
            }
        }
        return array_slice(array_values($out), 0, 20);
    }

    /**
     * Full paths of the categories this plugin filters out of its own
     * source query, e.g. "Apparel > Clearance".
     *
     * Reported so FeedAgentX can mirror them as its own rules. Until it
     * has, removing the filter here would let every product in these
     * categories reach the app with no rule to catch it — and a store
     * whose default is on would start advertising them.
     *
     * Both the path and the term id go over: the app matches products by
     * path (it has no WordPress database to resolve an id against) but
     * keys its rules by term, so renaming a category re-keys the
     * existing rule instead of orphaning it and adding a second one.
     *
     * @return array<int, array{term_id: int, path: string}>
     */

    private function build_header(): Header
    {
        $target_countries = $this->settings->get_target_countries();

        return new Header(
            // Back-compat single country: explicit setting, else first target.
            target_country: $this->settings->get_target_country() ?? $target_countries[0] ?? null,
            store_country: $this->settings->get_store_country(),
            target_countries: $target_countries,
            seller: $this->seller_mapper->build_header_block(),
            eligibility: [
                'search' => $this->settings->is_search_enabled(),
                'checkout' => $this->settings->is_checkout_enabled(),
                'ads' => $this->settings->is_ads_enabled(),
            ],
        );
    }
}
