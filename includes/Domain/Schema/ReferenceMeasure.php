<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class ReferenceMeasure
{
    public function __construct(
        public readonly int $value,
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
