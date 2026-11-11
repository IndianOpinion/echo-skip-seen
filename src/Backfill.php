<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Db;
use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Exception\SchemaException;
use IndianOpinion\EchoSkipSeen\Exception\StoreException;
use WP_Post;

/**
 * Seeds the table from existing posts (PRD 4.8).
 *
 * Walks posts by id keyset (id greater than the last id seen), never by
 * offset. Idempotent: a second run writes nothing and reports the rows as
 * already present. Trashed posts are included; auto-drafts are not.
 */
final class Backfill
{
    /** Stored cursor and running totals of the admin backfill. Not autoloaded. */
    public const CURSOR_OPTION = 'echo_skip_seen_backfill_cursor';

    public function __construct(
        private readonly Db $db,
        private readonly Table $table,
        private readonly Schema $schema,
        private readonly Settings $settings,
        private readonly Recorder $recorder,
        private readonly Counters $counters,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Runs one batch.
     *
     * @throws SchemaException When the table is not ready.
     * @throws StoreException  When the posts query fails.
     */
    public function batch(BackfillRequest $request): BackfillResult
    {
        if (!$this->schema->isReady()) {
            throw new SchemaException('the table is missing or incomplete; run verify-schema');
        }

        $postTypes = $request->postTypes !== [] ? $request->postTypes : $this->settings->postTypes();
        $metaKeys = $request->metaKeys ?? $this->settings->metaKeys();
        $result = new BackfillResult();
        $result->lastId = $request->afterId;

        if ($postTypes === []) {
            $result->done = true;

            return $result;
        }

        $rows = $this->posts($request, $postTypes);
        $ids = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
        if ($ids !== []) {
            if (function_exists('_prime_post_caches')) {
                _prime_post_caches($ids, false, false);
            }
            update_meta_cache('post', $ids);
        }

        $retention = $this->settings->retentionDays();
        $floor = $retention > 0 && !$request->includeExpired ? $this->clock->now() - $retention * 86400 : null;

        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $result->lastId = max($result->lastId, $id);
            $result->scanned++;

            if ($metaKeys === []) {
                continue;
            }

            $post = get_post($id);
            if (!$post instanceof WP_Post || SourceUrl::isRevisionOrAutosave($post)) {
                continue;
            }

            $url = SourceUrl::find($id, $metaKeys);
            if ($url === null) {
                continue;
            }
            $result->withUrl++;

            $time = self::postTime($row);
            if ($floor !== null && $time < $floor) {
                $result->expired++;
                continue;
            }

            if (!$this->counts($post, $url, $postTypes)) {
                continue;
            }

            try {
                $keys = $this->recorder->keysFor($url);
            } catch (\Throwable) {
                $keys = null;
            }
            if ($keys === null) {
                $result->rejected++;
                continue;
            }

            $expected = count($keys->rows());
            if ($request->dryRun) {
                $present = count($this->table->find($keys->hashes()));
                $result->present += $present;
                $result->written += max(0, $expected - $present);
                continue;
            }

            $written = $this->table->insert($keys, $id, $time);
            $result->written += $written;
            $result->present += $expected - $written;
        }

        if (!$request->dryRun) {
            $this->counters->increment('recorded', $result->written);
        }

        $result->done = count($rows) < $request->batchSize;

        return $result;
    }

    /**
     * Runs batches until done, calling $progress after each one.
     *
     * @param (callable(BackfillResult): void)|null $progress
     *
     * @throws SchemaException
     * @throws StoreException
     */
    public function run(BackfillRequest $request, ?callable $progress = null): BackfillResult
    {
        $total = new BackfillResult();
        $total->lastId = $request->afterId;
        do {
            $batch = $this->batch($request->withAfterId($total->lastId));
            $total->add($batch);
            if ($progress !== null) {
                $progress($batch);
            }
        } while (!$batch->done);

        return $total;
    }

    /**
     * Number of posts a run would scan, for a progress bar.
     *
     * @param list<string> $postTypes
     *
     * @throws StoreException
     */
    public function countPosts(BackfillRequest $request, array $postTypes = []): int
    {
        $postTypes = $postTypes !== [] ? $postTypes : ($request->postTypes !== [] ? $request->postTypes : $this->settings->postTypes());
        if ($postTypes === []) {
            return 0;
        }
        [$where, $args] = $this->where($request, $postTypes);

        return (int) $this->db->value('SELECT COUNT(*) FROM %i WHERE ' . $where, $args);
    }

    /**
     * @param list<string> $postTypes
     * @return list<array<string, mixed>>
     *
     * @throws StoreException
     */
    private function posts(BackfillRequest $request, array $postTypes): array
    {
        [$where, $args] = $this->where($request, $postTypes);
        $args[] = $request->batchSize;

        return $this->db->rows(
            'SELECT ID, post_date, post_date_gmt FROM %i WHERE ' . $where . ' ORDER BY ID ASC LIMIT %d',
            $args
        );
    }

    /**
     * @param list<string> $postTypes
     * @return array{0: literal-string, 1: list<string|int>}
     */
    private function where(BackfillRequest $request, array $postTypes): array
    {
        $in = implode(', ', array_fill(0, count($postTypes), '%s'));
        $where = 'ID > %d AND post_type IN (' . $in . ") AND post_status <> 'auto-draft'";
        $args = array_merge([$this->db->postsTable(), $request->afterId], $postTypes);
        if ($request->since !== null) {
            $where .= ' AND post_date_gmt >= %s';
            $args[] = Table::toDate($request->since);
        }

        return [$where, $args];
    }

    /**
     * @param list<string> $postTypes
     */
    private function counts(WP_Post $post, string $url, array $postTypes): bool
    {
        return SourceUrl::shouldRecord($post, $url, $postTypes);
    }

    /**
     * The post's GMT date, falling back to its local date for drafts that have none.
     *
     * @param array<string, mixed> $row
     */
    private static function postTime(array $row): int
    {
        $gmt = (string) ($row['post_date_gmt'] ?? '');
        if ($gmt !== '' && !str_starts_with($gmt, '0000')) {
            return Table::toTime($gmt);
        }

        $local = (string) ($row['post_date'] ?? '');
        if ($local !== '' && !str_starts_with($local, '0000') && function_exists('get_gmt_from_date')) {
            return Table::toTime(get_gmt_from_date($local));
        }

        return Table::toTime($local);
    }
}
