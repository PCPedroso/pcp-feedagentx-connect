<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Barcode
{
    public const TYPE_GTIN = 'gtin';
    public const TYPE_UPC = 'upc';
    public const TYPE_EAN = 'ean';
    public const TYPE_ISBN = 'isbn';
    public const TYPE_MPN = 'mpn';

    public function __construct(
        public readonly string $type,
        public readonly string $value,
    ) {
    }

    public function to_array(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
        ];
    }
}
