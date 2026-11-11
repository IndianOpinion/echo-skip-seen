<?php

/**
 * Plugin Name: Echo Skip Seen logging
 * Description: Example of writing every skip, record and failure to a log of your own.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

add_action('echo_skip_seen_skipped', static function (string $url, int $postId, string $kind): void {
    error_log(sprintf('[import] skipped %s (already post %d, %s key)', $url, $postId, $kind));
}, 10, 3);

add_action('echo_skip_seen_recorded', static function (string $url, int $postId, int $rows): void {
    error_log(sprintf('[import] recorded %s for post %d (%d new rows)', $url, $postId, $rows));
}, 10, 3);

add_action('echo_skip_seen_failed_open', static function (string $where, string $reason): void {
    error_log(sprintf('[import] echo-skip-seen passed through in %s: %s', $where, $reason));
}, 10, 2);
