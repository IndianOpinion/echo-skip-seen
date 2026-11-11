<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

/**
 * Runs a site-supplied regular expression without letting a bad pattern
 * raise a PHP warning. A pattern that does not compile is treated as no match.
 */
final class Regex
{
    /**
     * @return array<int|string, string>|null The matches, or null for no match or a broken pattern.
     */
    public static function match(string $pattern, string $subject): ?array
    {
        $failed = false;
        set_error_handler(static function () use (&$failed): bool {
            $failed = true;

            return true;
        });

        try {
            $result = preg_match($pattern, $subject, $matches);
        } finally {
            restore_error_handler();
        }

        if ($failed || $result !== 1) {
            return null;
        }

        return $matches;
    }
}
