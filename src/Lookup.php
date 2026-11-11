<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Exception\EchoSkipSeenException;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Keys\Url;

/**
 * The lookup path (PRD 4.6 and 4.7).
 *
 * Answers "has this article been imported?" with one indexed query per
 * distinct URL per request. Every failure fails open: the Echo callback
 * returns the value it was given, the public function returns false.
 */
final class Lookup
{
    public const DEFAULT_CACHE_CAP = 5000;

    /** Minimum gap between two last-seen refreshes of one row (PRD 68). */
    public const TOUCH_INTERVAL = 3600;

    /** @var array<string, array{post_id: int, kind: int}> Hash to hit. */
    private array $hits = [];

    /** @var array<string, true> Hash pair to known miss. */
    private array $misses = [];

    /** @var array<string, true> Hashes refreshed in this request. */
    private array $touched = [];

    private bool $breakerOpen = false;

    /** Site the caches belong to; a switch_to_blog() empties them. */
    private int $site = 0;

    public function __construct(
        private readonly Settings $settings,
        private readonly UrlGate $gate,
        private readonly KeyFactory $keys,
        private readonly Table $table,
        private readonly Schema $schema,
        private readonly KeyVersions $versions,
        private readonly Counters $counters,
        private readonly FailOpen $failOpen,
        private readonly Log $log,
        private readonly Clock $clock,
        private readonly int $cacheCap = self::DEFAULT_CACHE_CAP,
    ) {
    }

    /**
     * Callback for Echo's duplicate filter. Returns true for a seen URL and
     * the incoming value, unchanged, in every other case (PRD 62 to 69).
     */
    public function echoFilter(mixed $value): mixed
    {
        if (!is_string($value) || trim($value) === '' || !Url::looksLikeHttp($value)) {
            return $value;
        }

        return $this->check($value, 'echo') === true ? true : $value;
    }

    /**
     * The public question: true when seen, false otherwise and on any failure.
     */
    public function isSeen(string $url): bool
    {
        if (trim($url) === '' || !Url::looksLikeHttp($url)) {
            return false;
        }

        return $this->check($url, 'is_seen') === true;
    }

    /**
     * Updates the in-request cache after a record (PRD 70).
     */
    public function remember(KeySet $keys, int $postId): void
    {
        $site = get_current_blog_id();
        if ($site !== $this->site) {
            $this->forgetAll();
            $this->site = $site;
        }
        foreach ($keys->rows() as $row) {
            $this->hits[$row['hash']] = ['post_id' => $postId, 'kind' => $row['kind']];
        }
        $this->misses = [];
        $this->cap();
    }

    public function forgetAll(): void
    {
        $this->hits = [];
        $this->misses = [];
        $this->touched = [];
        $this->breakerOpen = false;
    }

    public function breakerOpen(): bool
    {
        return $this->breakerOpen;
    }

    /**
     * @return bool|null True for seen, false for a miss, null when the plugin passed (disabled, not ours, failure).
     */
    private function check(string $url, string $where): ?bool
    {
        $site = get_current_blog_id();
        if ($site !== $this->site) {
            $this->forgetAll();
            $this->site = $site;
        }

        try {
            if (!$this->settings->enabled()) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->failOpen->report($where, 'settings: ' . get_class($e), $url);

            return null;
        }

        try {
            $admitted = $this->gate->admit($url, 'lookup');
        } catch (\Throwable $e) {
            $this->failOpen->report($where, 'url filter threw ' . get_class($e), $url);

            return null;
        }
        if ($admitted === null) {
            return null;
        }

        try {
            $keys = $this->keys->forUrl($admitted);
        } catch (\Throwable $e) {
            $this->failOpen->report($where, 'no key: ' . $e->getMessage(), $url);

            return null;
        }

        $hit = $this->cachedHit($keys);
        if ($hit !== null) {
            $this->counters->increment('checked');

            return $this->onHit($url, $keys, $hit['post_id'], $hit['kind'], null);
        }
        if (isset($this->misses[$this->pairKey($keys)])) {
            $this->counters->increment('checked');

            return false;
        }

        if ($this->breakerOpen) {
            $this->failOpen->report($where, 'circuit open after a database error', $url, false);

            return null;
        }

        if (!$this->schema->isReady()) {
            $this->failOpen->report($where, 'schema not ready', $url);

            return null;
        }

        try {
            $rows = $this->table->find($keys->hashes());
            $retention = $this->settings->retentionDays();
            $accepted = $this->versions->accepted($retention);
        } catch (EchoSkipSeenException $e) {
            $this->breakerOpen = true;
            $this->failOpen->report($where, $e->getMessage(), $url);

            return null;
        } catch (\Throwable $e) {
            $this->breakerOpen = true;
            $this->failOpen->report($where, 'lookup threw ' . get_class($e), $url);

            return null;
        }

        $this->counters->increment('checked');

        $cutoff = $retention > 0 ? $this->clock->now() - $retention * 86400 : null;
        $match = null;
        foreach ($rows as $row) {
            if ($cutoff !== null && $row['last_seen'] < $cutoff) {
                continue;
            }
            if ($row['kind'] === KeySet::KIND_CANONICAL && !in_array($row['version'], $accepted, true)) {
                continue;
            }
            if ($match === null || $row['kind'] < $match['kind']) {
                $match = $row;
            }
        }

        if ($match === null) {
            $this->misses[$this->pairKey($keys)] = true;
            $this->cap();

            return false;
        }

        foreach ($keys->hashes() as $hash) {
            $this->hits[$hash] = ['post_id' => $match['post_id'], 'kind' => $match['kind']];
        }
        $this->cap();

        return $this->onHit($url, $keys, $match['post_id'], $match['kind'], $match);
    }

    /**
     * @param array{hash: string, last_seen: int}|null $row
     */
    private function onHit(string $url, KeySet $keys, int $postId, int $kind, ?array $row): bool
    {
        $this->counters->increment('skipped');

        $now = $this->clock->now();
        if ($row !== null && !isset($this->touched[$row['hash']]) && $row['last_seen'] < $now - self::TOUCH_INTERVAL) {
            $this->touched[$row['hash']] = true;
            try {
                $this->table->touch([$row['hash']], $now);
            } catch (\Throwable $e) {
                $this->log->debug('could not refresh last seen: ' . $e->getMessage(), $url);
            }
        }

        $this->log->debug('skipped (' . KeySet::kindName($kind) . ' match, post ' . $postId . ')', $url);

        try {
            do_action('echo_skip_seen_skipped', $url, $postId, KeySet::kindName($kind));
        } catch (\Throwable $e) {
            $this->log->debug('skipped listener threw ' . get_class($e), $url);
        }

        return true;
    }

    /**
     * @return array{post_id: int, kind: int}|null
     */
    private function cachedHit(KeySet $keys): ?array
    {
        foreach ($keys->hashes() as $hash) {
            if (isset($this->hits[$hash])) {
                return $this->hits[$hash];
            }
        }

        return null;
    }

    private function pairKey(KeySet $keys): string
    {
        return $keys->canonicalHash . $keys->plainHash;
    }

    /**
     * Keeps each cache under the cap by dropping its oldest entries.
     */
    private function cap(): void
    {
        while (count($this->hits) > $this->cacheCap) {
            unset($this->hits[(string) array_key_first($this->hits)]);
        }
        while (count($this->misses) > $this->cacheCap) {
            unset($this->misses[(string) array_key_first($this->misses)]);
        }
    }
}
