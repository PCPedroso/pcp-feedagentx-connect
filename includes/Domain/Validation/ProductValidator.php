<?php
namespace FeedAgentX\Domain\Validation;
defined('ABSPATH') || exit;

use FeedAgentX\Domain\Schema\Product;

/** Validate the data each variant contributes to the Stable OpenAI feed. */
final class ProductValidator
{
    public function validate(Product $product, array $store_context = []): ValidationResult
    {
        $result = new ValidationResult();
        if (empty($product->variants)) $result->add_error('variants', 'required', 'Product must have at least one sellable variant.');
        if (trim($product->brand ?? '') === '' || preg_match('/^(unknown|null|n\/a)$/i', trim($product->brand ?? ''))) {
            $result->add_error('brand', 'required', 'Set the actual product brand in FeedAgentX → Brand.');
        }
        $seller = $store_context['seller'] ?? [];
        $ids = [];
        $combinations = [];
        $group_keys = null;
        foreach ($product->variants as $i => $variant) {
            $path = "variants.$i";
            if (trim($variant->id) === '' || isset($ids[$variant->id])) $result->add_error("$path.item_id", 'invalid_id', 'Every variant needs a unique stable identifier.');
            $ids[$variant->id] = true;
            $title = $variant->title ?: $product->title;
            if (trim($title ?? '') === '') $result->add_error("$path.title", 'required', 'Title is required.');
            elseif ($this->length($title) > 150) $result->add_warning("$path.title", 'recommended_length', 'Aim for a title of at most 150 characters.');
            $description = $variant->description?->plain ?: $product->description?->plain;
            if (trim($description ?? '') === '') $result->add_error("$path.description", 'required', 'Description is required.');
            elseif ($this->length($description) > 5000) $result->add_warning("$path.description", 'recommended_length', 'Aim for a description of at most 5000 characters.');
            $https = $product->is_ads_eligible === true;
            if (! $this->url($variant->url ?: $product->url, $https)) $result->add_error("$path.url", 'invalid_url', $https ? 'Ads require a public HTTPS product URL.' : 'A public HTTP(S) product URL is required.');
            $images = array_merge($variant->media, $product->media);
            if (empty($images) || ! $this->url($images[0]->url, $https)) $result->add_error("$path.image_url", 'invalid_url', 'A public main image URL is required.');
            $name = $seller['name'] ?? $variant->seller?->name ?? '';
            if (trim($name) === '' || preg_match('/^(unknown|null|n\/a)$/i', trim($name))) $result->add_error('seller_name', 'required', 'Set the actual seller name.');
            if ($variant->price === null || $variant->price->amount <= 0 || ! preg_match('/^[A-Z]{3}$/', $variant->price->currency)) $result->add_error("$path.price", 'invalid_price', 'A positive price with a three-letter currency is required.');
            if ($variant->availability === null) $result->add_error("$path.availability", 'required', 'Availability is required.');
            if ($variant->gtin !== null && ! $this->gtin($variant->gtin)) $result->add_error("$path.gtin", 'invalid_gtin', 'GTIN needs 8, 12, 13 or 14 digits and a valid check digit.');
            if ($variant->weight !== null && (! is_numeric($variant->weight['value'] ?? '') || (float) $variant->weight['value'] <= 0 || ! in_array($variant->weight['unit'] ?? '', ['g', 'kg', 'oz', 'lb'], true))) $result->add_error("$path.weight", 'invalid_weight', 'Weight needs a positive decimal and a supported unit.');
            if ($variant->dimensions !== null) {
                $axes = array_intersect_key($variant->dimensions, array_flip(['length', 'width', 'height']));
                if (count($axes) < 2) $result->add_warning("$path.dimensions", 'omitted', 'Supply at least two dimensions; incomplete dimensions are omitted from export.');
                elseif (! in_array($variant->dimensions['unit'] ?? '', ['in', 'cm', 'ft', 'm', 'mm'], true) || count(array_filter($axes, static fn ($v) => is_numeric($v) && (float) $v > 0)) !== count($axes)) $result->add_error("$path.dimensions", 'invalid_dimensions', 'Dimensions need positive decimal values and a supported unit.');
            }
            if ($product->listing_has_variations) {
                if ($product->id === $variant->id || $product->id === '') $result->add_error("$path.group_id", 'invalid_group', 'Parent and variant identifiers must differ.');
                $options = [];
                foreach ($variant->variant_options as $option) {
                    if (trim($option->name) === '' || trim($option->value) === '') $result->add_error("$path.variant_dict", 'empty_option', 'Select an explicit value for every option.');
                    $options[$option->name] = $option->value;
                }
                if (empty($options)) $result->add_error("$path.variant_dict", 'required', 'Variations need explicit selected options.');
                ksort($options);
                $keys = array_keys($options);
                if ($group_keys !== null && $keys !== $group_keys) $result->add_error("$path.variant_dict", 'inconsistent_options', 'All variations need the same option names.');
                $group_keys = $keys;
                $key = json_encode($options);
                if (isset($combinations[$key])) $result->add_error("$path.variant_dict", 'duplicate_options', 'Variation selections must be unique.');
                $combinations[$key] = true;
            }
        }
        foreach (['url', 'privacy_policy_url', 'tos_url', 'return_policy_url'] as $field) {
            if (! empty($seller[$field]) && ! $this->url($seller[$field])) $result->add_error("seller.$field", 'invalid_url', 'Use a public HTTP(S) URL.');
        }
        if ($product->is_eligible_checkout === true) {
            foreach (['privacy_policy_url', 'tos_url'] as $field) {
                if (empty($seller[$field])) $result->add_error("seller.$field", 'required', 'Checkout requires privacy policy and terms URLs, plus an approved integration.');
            }
        }
        return $result;
    }

    private function gtin(string $value): bool
    {
        if (! preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $value)) return false;
        $digits = array_map('intval', str_split($value));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        return (10 - $sum % 10) % 10 === $check;
    }

    private function url(?string $value, bool $https = false): bool
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false) return false;
        $parts = wp_parse_url($value);
        if (! in_array(strtolower($parts['scheme'] ?? ''), $https ? ['https'] : ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        if ($host === 'localhost' || preg_match('/\.(local|localhost|internal)$/', $host)) return false;
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        return $host !== '';
    }

    private function length(string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); }
}
