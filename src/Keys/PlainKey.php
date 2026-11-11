<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

/**
 * The plain key: a conservative exact-match key (PRD 4.3).
 *
 * Frozen. It must never change once released, because it is the safety
 * net that keeps exact repeats matching across canonical rule changes.
 *
 * Definition, in order: trim; decode HTML entities once; remove the scheme
 * and "://"; remove the fragment; remove user information; remove one
 * leading "www."; remove one trailing slash before the query; lower-case
 * the whole string (ASCII letters only). The query keeps its order.
 */
final class PlainKey
{
    public static function make(string $cleanUrl): string
    {
        $value = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://~i', '', $cleanUrl, 1);

        $hash = strpos($value, '#');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        $question = strpos($value, '?');
        $before = $question === false ? $value : substr($value, 0, $question);
        $query = $question === false ? null : substr($value, $question + 1);

        $slash = strpos($before, '/');
        $authority = $slash === false ? $before : substr($before, 0, $slash);
        $rest = $slash === false ? '' : substr($before, $slash);
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        $before = $authority . $rest;

        if (Ascii::startsWithIgnoreCase($before, 'www.')) {
            $before = substr($before, 4);
        }
        if (str_ends_with($before, '/')) {
            $before = substr($before, 0, -1);
        }

        return Ascii::lower($before . ($query === null ? '' : '?' . $query));
    }
}
