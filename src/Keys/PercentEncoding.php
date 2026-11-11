<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

/**
 * Percent-encoding normalisation in the RFC 3986 style.
 *
 * - Hex digits in an escape are upper-cased.
 * - An escape of an unreserved character (letters, digits, "-", ".", "_",
 *   "~") is decoded. Every other escape stays encoded, so "%2F" never
 *   becomes a path separator.
 * - Raw bytes that are not printable ASCII (UTF-8 text, spaces, controls)
 *   and the characters RFC 3986 never allows raw are encoded, so a raw
 *   UTF-8 path and its encoded form meet.
 * - A "%" that does not start a valid escape is encoded as "%25".
 */
final class PercentEncoding
{
    public static function normalise(string $value): string
    {
        $result = preg_replace_callback(
            '/%([0-9A-Fa-f]{2})|%|[^\x21-\x7E]|["<>\\\\^`{|}]/',
            static function (array $match): string {
                if (isset($match[1])) {
                    $byte = chr((int) hexdec($match[1]));
                    if (self::isUnreserved($byte)) {
                        return $byte;
                    }

                    return '%' . Ascii::upper($match[1]);
                }

                return sprintf('%%%02X', ord($match[0]));
            },
            $value
        );

        return $result ?? $value;
    }

    public static function isUnreserved(string $byte): bool
    {
        return $byte !== '' && strspn($byte, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~') === strlen($byte);
    }
}
