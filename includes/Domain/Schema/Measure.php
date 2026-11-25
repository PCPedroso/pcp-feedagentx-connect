<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class Measure
{
    public function __construct(
        public readonly float $value,
        public readonly string $unit,
    ) {
    }

    public function to_array(): array
    {
        return [
            'value' => $this->value,
            'unit' => $this->unit,
        ];
    }
}
