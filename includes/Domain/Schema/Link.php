<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Link
{
    public const TYPE_PRIVACY_POLICY = 'privacy_policy';
    public const TYPE_TERMS_OF_SERVICE = 'terms_of_service';
    public const TYPE_REFUND_POLICY = 'refund_policy';
    public const TYPE_SHIPPING_POLICY = 'shipping_policy';
    public const TYPE_FAQ = 'faq';

    public const ALLOWED_TYPES = [
        self::TYPE_PRIVACY_POLICY,
        self::TYPE_TERMS_OF_SERVICE,
        self::TYPE_REFUND_POLICY,
        self::TYPE_SHIPPING_POLICY,
        self::TYPE_FAQ,
    ];

    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $title = null,
    ) {
    }

    public function to_array(): array
    {
        $out = [
            'type' => $this->type,
            'url' => $this->url,
        ];
        if ($this->title !== null) {
            $out['title'] = $this->title;
        }
        return $out;
    }
}
