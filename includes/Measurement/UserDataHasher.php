<?php

namespace FeedAgentX\Measurement;

defined('ABSPATH') || exit;

/**
 * Normalises and SHA-256 hashes customer identifiers the way OpenAI's
 * Pixel and Conversions API expect, so a hash made here matches the one
 * OpenAI makes from the same person. Raw values never leave the site.
 *
 * Each method returns null when there is nothing valid to send; callers
 * omit the field rather than send an empty or wrong hash.
 */
final class UserDataHasher
{
    public static function sha256(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Trim and lowercase. */
    public static function email(string $email): ?string
    {
        $email = strtolower(trim($email));
        if ($email === '' || ! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            return null;
        }
        return self::sha256($email);
    }

    /**
     * 8–15 digits including the country calling code, with no leading
     * + or zeroes: "+1 (415) 555-2671" → "14155552671".
     *
     * WooCommerce stores whatever the shopper typed, usually in national
     * format ("0532 123 45 67"). Hashing that as-is would never match, so
     * the billing country's calling code is added when the number does
     * not already carry one.
     */
    public static function normalize_phone(string $phone, string $calling_code = ''): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        $international = str_starts_with($phone, '+') || str_starts_with(ltrim($phone, " \t().-"), '00');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');
        $code = ltrim((string) preg_replace('/\D+/', '', $calling_code), '0');
        // North American numbering plan: WooCommerce gives Puerto Rico
        // "+1787", Jamaica "+1876" and so on, but the area code is already
        // part of the national number. The country code is just "1".
        if (str_starts_with($code, '1')) {
            $code = '1';
        }

        if (! $international && $code !== '') {
            // "905321234567" typed without a + already has the code; a
            // national number never starts with its own country code at
            // a length that leaves eight or more digits after it.
            $has_code = str_starts_with($digits, $code) && strlen($digits) - strlen($code) >= 8;
            if (! $has_code) {
                $digits = $code . $digits;
            }
        }

        return preg_match('/^\d{8,15}$/', $digits) ? $digits : null;
    }

    public static function phone(string $phone, string $calling_code = ''): ?string
    {
        $normalized = self::normalize_phone($phone, $calling_code);
        return $normalized === null ? null : self::sha256($normalized);
    }

    /**
     * Lowercase, drop whitespace and ASCII punctuation, keep everything
     * else: "O'Connor" → "oconnor", "José" → "josé".
     */
    public static function normalize_name(string $name): ?string
    {
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = preg_replace('/[\s!-\/:-@\[-`{-~]+/u', '', $name) ?? '';
        return $name === '' ? null : $name;
    }

    public static function name(string $name): ?string
    {
        $normalized = self::normalize_name($name);
        return $normalized === null ? null : self::sha256($normalized);
    }

    /** Trim only; case and every other character are kept. */
    public static function external_id(string $id): ?string
    {
        $id = trim($id);
        return $id === '' ? null : self::sha256($id);
    }
}
