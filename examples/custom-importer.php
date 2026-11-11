<?php

/**
 * Plugin Name: Echo Skip Seen with another importer
 * Description: Example of an importer that does not use Echo asking and recording directly.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

/**
 * Imports one feed item, skipping it when the article is already on the site.
 *
 * @param array{link: string, title: string, content: string} $item
 */
function my_importer_handle_item(array $item): ?int
{
    // Ask before downloading anything. False on any failure, so the import goes on.
    if (function_exists('echo_skip_seen_is_seen') && echo_skip_seen_is_seen($item['link'])) {
        return null;
    }

    // A filter-only importer can ask the same question this way.
    if (apply_filters('echo_skip_seen_is_seen', false, $item['link']) === true) {
        return null;
    }

    $postId = wp_insert_post([
        'post_title' => $item['title'],
        'post_content' => $item['content'],
        'post_status' => 'draft',
    ]);
    if (!is_int($postId) || $postId === 0) {
        return null;
    }

    // Record after the post exists. Either call works; the action needs no function_exists().
    do_action('echo_skip_seen_record_url', $item['link'], $postId);

    return $postId;
}
