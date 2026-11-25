<?php

namespace FeedAgentX\Domain\Mappers;

defined('ABSPATH') || exit;

use FeedAgentX\Domain\Currency\MinorUnitsConverter;
use FeedAgentX\Domain\Schema\Price;
use FeedAgentX\Infra\Exceptions\InvalidArgumentException;
use FeedAgentX\Infra\Logger;

/**
 * Builds ACP Price objects from WooCommerce decimal price strings.
 */
final class PriceMapper
{
    public function __construct(
        private readonly MinorUnitsConverter $converter,
    ) {
    }

    /**
     * Build a Price from a decimal amount + currency. Returns null if the
     * amount is empty/non-numeric (variable products may have empty prices
     * at the parent level).
     */
    public function from_decimal(string|float|int|null $amount, string $currency): ?Price
    {
        if ($amount === null || $amount === '' || $amount === false) {
            return null;
        }

        try {
            $minor = $this->converter->to_minor_units($amount, $currency);
            $code = $this->converter->normalize_currency($currency);
            return new Price($minor, $code);
        } catch (InvalidArgumentException $e) {
            Logger::warning('PriceMapper failed to convert amount', [
                'amount' => $amount,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
