<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * Feed header — store-level metadata the SaaS needs when assembling the final
 * flat (file-upload) feed. Carries the merchant defaults the flat spec makes
 * required per row: store_country, target_countries, the seller block
 * (seller_name/seller_url/policy URLs) and eligibility defaults. feed_id /
 * account_id / target_merchant stay optional SaaS-side concerns.
 */
final class Header
{
    /**
     * @param string[]   $target_countries ISO 3166-1 alpha-2 codes.
     * @param array|null $seller           {name, url, privacy_policy_url, tos_url, return_policy_url, return_deadline_days}
     * @param array|null $eligibility      {search: bool, checkout: bool, ads: bool}
     * @param array<int, array{term_id:int, path:string, include_children:bool}> $excluded_categories
     *                                      Categories this plugin filters out
     *                                      of its own source query, with the
     *                                      scope of that filter.
     */
    public function __construct(
        public readonly ?string $feed_id = null,
        public readonly ?string $account_id = null,
        public readonly ?string $target_merchant = null,
        public readonly ?string $target_country = null,
        public readonly ?string $store_country = null,
        public readonly array $target_countries = [],
        public readonly ?array $seller = null,
        public readonly ?array $eligibility = null,
        public readonly array $excluded_categories = [],
    ) {
    }

    public function to_array(): array
    {
        $out = [];
        if ($this->feed_id !== null) {
            $out['feed_id'] = $this->feed_id;
        }
        if ($this->account_id !== null) {
            $out['account_id'] = $this->account_id;
        }
        if ($this->target_merchant !== null) {
            $out['target_merchant'] = $this->target_merchant;
        }
        if ($this->target_country !== null) {
            $out['target_country'] = $this->target_country;
        }
        if ($this->store_country !== null) {
            $out['store_country'] = $this->store_country;
        }
        if (! empty($this->target_countries)) {
            $out['target_countries'] = $this->target_countries;
        }
        if ($this->seller !== null) {
            // Null members dropped so the SaaS can distinguish "unset" cleanly.
            $out['seller'] = array_filter($this->seller, static fn ($v) => $v !== null);
        }
        if ($this->eligibility !== null) {
            $out['eligibility'] = $this->eligibility;
        }
        // Reported so FeedAgentX can mirror these as its own rules before
        // this plugin stops filtering. Until it does, the products in these
        // categories never reach the app at all — which is why a merchant
        // cannot currently undo an exclusion from there.
        if (! empty($this->excluded_categories)) {
            $out['excluded_categories'] = array_values($this->excluded_categories);
        }
        return $out;
    }
}
