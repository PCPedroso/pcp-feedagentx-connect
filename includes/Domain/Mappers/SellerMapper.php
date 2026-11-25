<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Link;
use FeedAgentX\Domain\Schema\Seller;
use FeedAgentX\Infra\SettingsRepository;

/**
 * Builds the Seller object — store name + policy links.
 *
 * Collects URLs from:
 *   - WooCommerce settings (terms page, return policy)
 *   - WordPress privacy policy URL
 *   - SettingsRepository overrides (return_policy_url)
 */
final class SellerMapper
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    public function build(): Seller
    {
        $name = (string) get_bloginfo('name');
        $links = [];

        $privacy_url = $this->safe_url(get_privacy_policy_url());
        if ($privacy_url !== null) {
            $links[] = new Link(type: Link::TYPE_PRIVACY_POLICY, url: $privacy_url);
        }

        $tos_url = $this->safe_url($this->get_woocommerce_terms_url());
        if ($tos_url !== null) {
            $links[] = new Link(type: Link::TYPE_TERMS_OF_SERVICE, url: $tos_url);
        }

        $return_url = $this->safe_url($this->settings->get_return_policy_url());
        if ($return_url !== null) {
            $links[] = new Link(type: Link::TYPE_REFUND_POLICY, url: $return_url);
        }

        return new Seller(
            name: $name !== '' ? $name : null,
            url: $this->safe_url(home_url()),
            links: $links,
        );
    }

    /**
     * Store-level seller block for the feed header — flat keys per the
     * file-upload spec. This is the source of truth for seller_* fields;
     * the per-variant Seller object is kept for back-compat.
     */
    public function build_header_block(): array
    {
        $name = (string) get_bloginfo('name');

        return [
            'name' => $name !== '' ? $name : null,
            'url' => $this->safe_url(home_url()),
            'privacy_policy_url' => $this->safe_url(get_privacy_policy_url()),
            'tos_url' => $this->safe_url($this->get_woocommerce_terms_url()),
            'return_policy_url' => $this->safe_url($this->settings->get_return_policy_url()),
            'accepts_returns' => $this->settings->accepts_returns(),
            'return_deadline_days' => $this->settings->get_return_deadline_days(),
        ];
    }

    private function get_woocommerce_terms_url(): ?string
    {
        if (! function_exists('get_option')) {
            return null;
        }
        $terms_page_id = (int) get_option('woocommerce_terms_page_id', 0);
        if ($terms_page_id <= 0) {
            return null;
        }
        $url = get_permalink($terms_page_id);
        return is_string($url) ? $url : null;
    }

    private function safe_url(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        $clean = esc_url_raw($url);
        return $clean !== '' ? $clean : null;
    }

    private function truncate(string $value, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') <= $max ? $value : mb_substr($value, 0, $max, 'UTF-8');
        }
        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
