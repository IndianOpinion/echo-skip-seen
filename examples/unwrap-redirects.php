<?php

/**
 * Plugin Name: Echo Skip Seen redirect unwrapping
 * Description: Example of plugging a redirect-unwrapping tool into the pre-processing filter.
 *
 * Wrapper links (Google News by default) are left alone unless something
 * turns them into the publisher URL first. Return the publisher URL, or the
 * URL unchanged when you cannot. The filter runs on lookup and on record.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

add_filter('echo_skip_seen_url', static function (string $url, string $context): string {
    // A link shortener whose target is in a query parameter, as an example.
    $parts = wp_parse_url($url);
    if (($parts['host'] ?? '') === 'go.example.net' && isset($parts['query'])) {
        parse_str($parts['query'], $query);
        if (isset($query['to']) && is_string($query['to']) && str_starts_with($query['to'], 'https://')) {
            return $query['to'];
        }
    }

    return $url;
}, 10, 2);

// Treat another wrapper host the same way as Google News.
add_filter('echo_skip_seen_wrapper_hosts', static fn (array $hosts): array => array_merge($hosts, ['news.example.org']));
