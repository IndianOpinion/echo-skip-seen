<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Keys\Url;

/**
 * The record path (PRD 4.5): writes the keys of an imported article.
 *
 * Seen means imported. Nothing here runs on a lookup.
 */
final class Recorder
{
    public function __construct(
        private readonly Settings $settings,
        private readonly UrlGate $gate,
        private readonly KeyFactory $keys,
        private readonly Table $table,
        private readonly Schema $schema,
        private readonly Lookup $lookup,
        private readonly Counters $counters,
        private readonly FailOpen $failOpen,
        private readonly Log $log,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Records a URL and returns the number of new rows written. Never throws.
     */
    public function record(string $url, int $postId = 0, ?int $time = null): int
    {
        try {
            if (!$this->settings->enabled()) {
                return 0;
            }
            $keys = $this->keysFor($url);
        } catch (InvalidUrlException) {
            // Not a URL this plugin keys: ignored, not a failure (PRD 4.2 cases 3 and 4).
            return 0;
        } catch (\Throwable $e) {
            $this->failOpen->report('record', 'url filter threw ' . get_class($e), $url);

            return 0;
        }

        if ($keys === null) {
            return 0;
        }

        return $this->write($url, $keys, $postId, $time ?? $this->clock->now());
    }

    /**
     * Keys for a URL on the record path, or null when the URL is left alone.
     *
     * @throws InvalidUrlException When the URL cannot be keyed.
     * @throws \Throwable           When a pre-processing callback throws.
     */
    public function keysFor(string $url): ?KeySet
    {
        if (!Url::looksLikeHttp($url)) {
            return null;
        }

        $admitted = $this->gate->admit($url, 'record');
        if ($admitted === null) {
            return null;
        }

        return $this->keys->forUrl($admitted);
    }

    /**
     * Writes rows for computed keys. Never throws.
     */
    public function write(string $url, KeySet $keys, int $postId, int $time): int
    {
        if (!$this->schema->isReady()) {
            $this->failOpen->report('record', 'schema not ready', $url);

            return 0;
        }

        try {
            $written = $this->table->insert($keys, $postId, $time);
        } catch (\Throwable $e) {
            $this->failOpen->report('record', $e->getMessage(), $url);

            return 0;
        }

        $this->lookup->remember($keys, $postId);
        $this->counters->increment('recorded', $written);
        $this->log->debug('recorded ' . $written . ' new row(s) for post ' . $postId, $url);

        try {
            do_action('echo_skip_seen_recorded', $url, $postId, $written);
        } catch (\Throwable $e) {
            $this->log->debug('recorded listener threw ' . get_class($e), $url);
        }

        return $written;
    }

    /**
     * Removes the rows of a post. Never throws.
     */
    public function forgetPost(int $postId): int
    {
        try {
            return $this->table->deleteByPost($postId);
        } catch (\Throwable $e) {
            $this->failOpen->report('delete', $e->getMessage());

            return 0;
        }
    }
}
