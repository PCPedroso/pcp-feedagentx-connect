<?php

namespace FeedAgentX\Domain\Currency;

defined('ABSPATH') || exit;

// Domain exceptions carry structured REST JSON data; HTML views escape values when rendering.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use FeedAgentX\Infra\Exceptions\InvalidArgumentException;

/**
 * Converts decimal amounts ("79.99") into ISO 4217 minor units (7999).
 *
 * Different currencies have different exponent values:
 *  - USD, EUR, GBP, TRY: 2 minor digits  (cents)
 *  - JPY, KRW, CLP:      0 minor digits  (whole units)
 *  - BHD, KWD, TND:      3 minor digits
 *
 * Operates on strings to avoid floating-point precision loss.
 */
final class MinorUnitsConverter
{
    /**
     * ISO 4217 currencies with non-default exponents.
     * Currencies not listed default to exponent 2.
     */
    private const EXPONENT_OVERRIDES = [
        // Zero-exponent (no fractional part)
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0,
        'JPY' => 0, 'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0,
        'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0,
        'XOF' => 0, 'XPF' => 0,

        // Three-exponent
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3,
        'OMR' => 3, 'TND' => 3,

        // Four-exponent (rare)
        'CLF' => 4, 'UYW' => 4,
    ];

    public function get_exponent(string $currency): int
    {
        $code = strtoupper(trim($currency));
        return self::EXPONENT_OVERRIDES[$code] ?? 2;
    }

    /**
     * Normalize a currency code: trim, uppercase, validate alpha-3.
     *
     * @throws InvalidArgumentException
     */
    public function normalize_currency(string $currency): string
    {
        $code = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            throw new InvalidArgumentException(
                sprintf('Currency code must be a 3-letter ISO 4217 code, got "%s".', $currency),
                ['currency' => $currency]
            );
        }
        return $code;
    }

    /**
     * Convert a decimal string/float/int amount to integer minor units.
     *
     * @param string|float|int $amount  E.g. "79.99", 79.99, "1,250.50"
     * @throws InvalidArgumentException
     */
    public function to_minor_units(string|float|int $amount, string $currency): int
    {
        $exponent = $this->get_exponent($currency);
        $normalized = $this->normalize_decimal_string($amount);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                sprintf('Empty amount for currency %s.', $currency),
                ['amount' => $amount, 'currency' => $currency]
            );
        }

        $negative = false;
        if ($normalized[0] === '-') {
            $negative = true;
            $normalized = substr($normalized, 1);
        }

        if (! preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException(
                sprintf('Amount "%s" is not a valid decimal number.', $amount),
                ['amount' => $amount, 'currency' => $currency]
            );
        }

        $parts = explode('.', $normalized);
        $integer_part = $parts[0];
        $fraction_part = $parts[1] ?? '';

        // Pad or truncate the fractional part to match the currency exponent.
        if (strlen($fraction_part) < $exponent) {
            $fraction_part = str_pad($fraction_part, $exponent, '0', STR_PAD_RIGHT);
        } elseif (strlen($fraction_part) > $exponent) {
            // Round half-up at the exponent boundary.
            $fraction_part = $this->round_fraction($fraction_part, $exponent, $integer_part, $negative);
            // round_fraction may have rolled over the integer part.
            [$integer_part, $fraction_part] = $this->normalize_after_rounding($integer_part, $fraction_part, $exponent);
        }

        $combined = ltrim($integer_part . $fraction_part, '0');
        if ($combined === '') {
            $combined = '0';
        }

        $value = (int) $combined;
        return $negative ? -$value : $value;
    }

    /**
     * Format integer minor units back to a decimal string (no currency symbol).
     */
    public function from_minor_units(int $minor, string $currency): string
    {
        $exponent = $this->get_exponent($currency);
        if ($exponent === 0) {
            return (string) $minor;
        }

        $negative = $minor < 0;
        $abs = (string) abs($minor);
        $abs = str_pad($abs, $exponent + 1, '0', STR_PAD_LEFT);
        $integer_part = substr($abs, 0, -$exponent);
        $fraction_part = substr($abs, -$exponent);

        $result = $integer_part . '.' . $fraction_part;
        return $negative ? '-' . $result : $result;
    }

    /**
     * Strip thousands separators and unify decimal separator to '.'.
     * Accepts both "1,250.50" and "1.250,50" formats.
     */
    private function normalize_decimal_string(string|float|int $amount): string
    {
        if (is_int($amount) || is_float($amount)) {
            return (string) $amount;
        }

        $str = trim($amount);
        if ($str === '') {
            return '';
        }

        // Remove spaces, currency symbols, NBSP.
        $str = preg_replace('/[\s\xC2\xA0]+/u', '', $str) ?? $str;
        $str = preg_replace('/[^\d.,\-]/', '', $str) ?? $str;

        $last_dot = strrpos($str, '.');
        $last_comma = strrpos($str, ',');

        if ($last_dot === false && $last_comma === false) {
            return $str;
        }

        // Decide which separator is the decimal separator: the rightmost one.
        if ($last_dot !== false && $last_comma !== false) {
            if ($last_comma > $last_dot) {
                $str = str_replace('.', '', $str);
                $str = str_replace(',', '.', $str);
            } else {
                $str = str_replace(',', '', $str);
            }
        } elseif ($last_comma !== false) {
            // Only commas — treat as decimal separator.
            $str = str_replace(',', '.', $str);
        }

        return $str;
    }

    private function round_fraction(string $fraction, int $exponent, string $integer_part, bool $negative): string
    {
        if ($exponent === 0) {
            // Round at the boundary: look at the first fractional digit.
            $first = (int) ($fraction[0] ?? '0');
            return $first >= 5 ? '__CARRY__' : '';
        }

        $kept = substr($fraction, 0, $exponent);
        $next = (int) ($fraction[$exponent] ?? '0');

        if ($next < 5) {
            return $kept;
        }

        // Round half up by adding 1 to the kept digits.
        $rounded = (int) $kept + 1;
        $expected_length = $exponent;
        $rounded_str = str_pad((string) $rounded, $expected_length, '0', STR_PAD_LEFT);

        if (strlen($rounded_str) > $expected_length) {
            // Carry into the integer part.
            return '__CARRY__:' . substr($rounded_str, 1);
        }

        return $rounded_str;
    }

    /**
     * Apply any carry signal returned from round_fraction.
     *
     * @return array{0:string,1:string}
     */
    private function normalize_after_rounding(string $integer_part, string $fraction_part, int $exponent): array
    {
        if ($fraction_part === '__CARRY__') {
            $integer_part = (string) ((int) $integer_part + 1);
            $fraction_part = str_repeat('0', $exponent);
            return [$integer_part, $fraction_part];
        }

        if (str_starts_with($fraction_part, '__CARRY__:')) {
            $integer_part = (string) ((int) $integer_part + 1);
            $fraction_part = substr($fraction_part, strlen('__CARRY__:'));
            return [$integer_part, $fraction_part];
        }

        return [$integer_part, $fraction_part];
    }
}
