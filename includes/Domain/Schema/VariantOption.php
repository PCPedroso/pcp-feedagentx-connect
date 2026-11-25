<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class VariantOption
{
    public function __construct(
        public readonly string $name,
        public readonly string $value,
    ) {
    }

    public function to_array(): array
    {
        return [
            'name' => $this->name,
            'value' => $this->value,
        ];
    }
}
