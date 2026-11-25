<?php

namespace FeedAgentX\Domain\Schema;

defined('ABSPATH') || exit;

/**
 * ACP Price object: integer minor units + ISO 4217 currency.
 */
final class Price
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    public function to_array(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
