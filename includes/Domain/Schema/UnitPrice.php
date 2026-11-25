<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

final class UnitPrice
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly Measure $measure,
        public readonly ReferenceMeasure $reference,
    ) {
    }

    public function to_array(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'measure' => $this->measure->to_array(),
            'reference' => $this->reference->to_array(),
        ];
    }
}
