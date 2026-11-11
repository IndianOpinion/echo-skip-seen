<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use WP_Post;

/**
 * Listens to post and post meta events and records imported posts (PRD 47 to 59).
 *
 * Importers often write the source meta after the post insert, so both the
 * insert and the meta write can trigger a record. Whichever comes first
 * records; the second is a no-op for that post and URL.
 */
final class PostWatcher
{
    /** @var array<string, true> Post id and URL pairs already attempted in this request. */
    private array $attempted = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly Recorder $recorder,
        private readonly FailOpen $failOpen,
    ) {
    }

    /**
     * Hooked to wp_after_insert_post.
     */
    public function afterInsert(int $postId, mixed $post = null): void
    {
        try {
            $post = $post instanceof WP_Post ? $post : get_post($postId);
            if ($post instanceof WP_Post) {
                $this->maybeRecord($post);
            }
        } catch (\Throwable $e) {
            $this->failOpen->report('record', 'post hook threw ' . get_class($e));
        }
    }

    /**
     * Hooked to added_post_meta and updated_post_meta.
     */
    public function metaChanged(mixed $metaId, mixed $postId, mixed $metaKey): void
    {
        try {
            if (!is_string($metaKey) || !is_numeric($postId)) {
                return;
            }
            if (!in_array($metaKey, $this->settings->metaKeys(), true)) {
                return;
            }
            $post = get_post((int) $postId);
            if ($post instanceof WP_Post) {
                $this->maybeRecord($post);
            }
        } catch (\Throwable $e) {
            $this->failOpen->report('record', 'meta hook threw ' . get_class($e));
        }
    }

    /**
     * Hooked to before_delete_post. Rows stay unless the filter asks otherwise (PRD 58).
     */
    public function beforeDelete(int $postId): void
    {
        try {
            if ((bool) apply_filters('echo_skip_seen_delete_on_post_delete', false)) {
                $this->recorder->forgetPost($postId);
            }
        } catch (\Throwable $e) {
            $this->failOpen->report('delete', 'delete hook threw ' . get_class($e));
        }
    }

    private function maybeRecord(WP_Post $post): void
    {
        if (SourceUrl::isRevisionOrAutosave($post)) {
            return;
        }

        $metaKeys = $this->settings->metaKeys();
        if ($metaKeys === []) {
            return;
        }

        $url = SourceUrl::find((int) $post->ID, $metaKeys);
        if ($url === null) {
            return;
        }

        $marker = get_current_blog_id() . '|' . $post->ID . '|' . $url;
        if (isset($this->attempted[$marker])) {
            return;
        }

        if (!SourceUrl::shouldRecord($post, $url, $this->settings->postTypes())) {
            return;
        }
        $this->attempted[$marker] = true;

        $this->recorder->record($url, (int) $post->ID);
    }
}
