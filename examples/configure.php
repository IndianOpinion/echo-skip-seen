<?php

/**
 * Plugin Name: Echo Skip Seen settings for this site
 * Description: Example configuration through filters. Drop into wp-content/mu-plugins/ and edit.
 *
 * Every line is optional. Defaults are listed in the README.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

// The meta key your importer writes the original article URL to. Open one
// imported post, show Custom Fields, and look for the field with the URL.
add_filter('echo_skip_seen_source_meta_keys', static fn (array $keys): array => array_merge($keys, ['source_url']));

// Imported posts land in a custom post type as well as in posts.
add_filter('echo_skip_seen_post_types', static fn (array $types): array => array_merge($types, ['news']));

// Keep rows for 30 days instead of 90.
add_filter('echo_skip_seen_retention_days', static fn (): int => 30);

// This publisher routes paths case-insensitively, so lower-case them.
add_filter(
    'echo_skip_seen_lowercase_path',
    static fn (bool $lower, string $host): bool => $lower || $host === 'example.com',
    10,
    2
);

// This publisher's Hindi edition carries the same articles: merge the hosts.
add_filter(
    'echo_skip_seen_host',
    static fn (string $host, string $url): string => $host === 'hindi.example.com' ? 'example.com' : $host,
    10,
    2
);

// One article filed under two section paths: key it by its numeric id.
add_filter('echo_skip_seen_article_id_rules', static function (array $rules): array {
    $rules['example.com'] = '~-(?<id>\d{6,})(?:\.html)?$~';

    return $rules;
});

// Drop one more tracking parameter everywhere.
add_filter('echo_skip_seen_tracking_params', static fn (array $names): array => array_merge($names, ['sessionid']));
