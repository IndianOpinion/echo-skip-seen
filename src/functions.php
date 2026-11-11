<?php

/**
 * Global convenience functions. Stable public API (PRD section 5).
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

use IndianOpinion\EchoSkipSeen\Plugin;

if (!function_exists('echo_skip_seen_is_seen')) {
    /**
     * True when the article behind this URL has already been imported.
     * False otherwise, and on any failure. Never throws.
     */
    function echo_skip_seen_is_seen(string $url): bool
    {
        try {
            $plugin = Plugin::instance();

            return $plugin !== null && $plugin->lookup()->isSeen($url);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('echo_skip_seen_record')) {
    /**
     * Records a URL as imported. Returns true when at least one new row was
     * written. Never throws.
     */
    function echo_skip_seen_record(string $url, int $post_id = 0): bool
    {
        try {
            $plugin = Plugin::instance();

            return $plugin !== null && $plugin->recorder()->record($url, $post_id) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('echo_skip_seen_keys')) {
    /**
     * The canonical key, the plain key and their hashes, or an empty array for a non-URL.
     *
     * @return array<string, string|int>
     */
    function echo_skip_seen_keys(string $url): array
    {
        try {
            $plugin = Plugin::instance();
            if ($plugin === null) {
                return [];
            }
            $keys = $plugin->recorder()->keysFor($url);

            return $keys === null ? [] : $keys->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
