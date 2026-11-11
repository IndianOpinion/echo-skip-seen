<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

/**
 * Locale-independent ASCII helpers.
 *
 * strtolower() follows the process locale on PHP 8.1, so a Turkish locale
 * would turn "I" into a dotless i. Keys must be identical everywhere, so
 * the plugin only ever folds the 26 ASCII letters.
 */
final class Ascii
{
    private const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz';

    public static function lower(string $value): string
    {
        return strtr($value, self::UPPER, self::LOWER);
    }

    public static function upper(string $value): string
    {
        return strtr($value, self::LOWER, self::UPPER);
    }

    public static function startsWithIgnoreCase(string $haystack, string $prefix): bool
    {
        return strncasecmp($haystack, $prefix, strlen($prefix)) === 0;
    }

    public static function endsWithIgnoreCase(string $haystack, string $suffix): bool
    {
        $length = strlen($suffix);
        if ($length > strlen($haystack)) {
            return false;
        }

        return self::lower(substr($haystack, -$length)) === self::lower($suffix);
    }
}
