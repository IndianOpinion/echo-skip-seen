<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Keys\Url;
use WP_Post;

/**
 * Finds an imported post's source URL and decides whether the post counts as imported.
 */
final class SourceUrl
{
    /**
     * The first configured meta value that is a valid http or https URL (PRD 49).
     *
     * @param list<string> $metaKeys
     */
    public static function find(int $postId, array $metaKeys): ?string
    {
        foreach ($metaKeys as $key) {
            $value = get_post_meta($postId, $key, true);
            if (is_string($value) && Url::isHttpUrl($value)) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The "counts as imported" decision with its filter (PRD 48).
     *
     * @param list<string> $postTypes
     */
    public static function shouldRecord(WP_Post $post, string $url, array $postTypes): bool
    {
        $default = in_array($post->post_type, $postTypes, true) && $post->post_status !== 'auto-draft';

        return (bool) apply_filters('echo_skip_seen_should_record', $default, $post, $url);
    }

    public static function isRevisionOrAutosave(WP_Post $post): bool
    {
        return $post->post_type === 'revision'
            || wp_is_post_revision($post) !== false
            || wp_is_post_autosave($post) !== false;
    }
}
