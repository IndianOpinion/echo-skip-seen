<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Db;

use IndianOpinion\EchoSkipSeen\Exception\StoreException;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;

/**
 * Reads and writes the seen-keys table of the current site.
 *
 * The table name is the site's table prefix plus a fixed suffix; nothing
 * from a request ever reaches it. Hashes travel as hex through UNHEX(),
 * so no binary string passes through the connection character set.
 *
 * @phpstan-type Row array{hash: string, kind: int, version: int, post_id: int, first_seen: int, last_seen: int}
 */
final class Table
{
    public const SUFFIX = 'echo_skip_seen';

    public function __construct(private readonly Db $db)
    {
    }

    public function name(): string
    {
        return $this->db->prefix() . self::SUFFIX;
    }

    public static function nameForPrefix(string $prefix): string
    {
        return $prefix . self::SUFFIX;
    }

    /**
     * Rows whose hash is one of the given hashes, whatever their age.
     *
     * @param list<string> $hashes Lower-case hex MD5 hashes.
     * @return list<Row>
     *
     * @throws StoreException
     */
    public function find(array $hashes): array
    {
        $hashes = self::validHashes($hashes);
        if ($hashes === []) {
            return [];
        }

        $in = implode(', ', array_fill(0, count($hashes), 'UNHEX(%s)'));
        $rows = $this->db->rows(
            'SELECT LOWER(HEX(key_hash)) AS h, key_kind, key_version, post_id, first_seen, last_seen'
            . ' FROM %i WHERE key_hash IN (' . $in . ')',
            array_merge([$this->name()], $hashes)
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'hash' => strtolower((string) ($row['h'] ?? '')),
                'kind' => (int) ($row['key_kind'] ?? 0),
                'version' => (int) ($row['key_version'] ?? 0),
                'post_id' => (int) ($row['post_id'] ?? 0),
                'first_seen' => self::toTime($row['first_seen'] ?? null),
                'last_seen' => self::toTime($row['last_seen'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Writes the rows of a key set and returns how many were new.
     *
     * Existing rows keep their post id; their last-seen time moves forward
     * to $time when it is older (PRD 54).
     *
     * @throws StoreException
     */
    public function insert(KeySet $keys, int $postId, int $time): int
    {
        $rows = $keys->rows();
        $values = [];
        $args = [$this->name()];
        $stamp = self::toDate($time);
        foreach ($rows as $row) {
            $values[] = '(UNHEX(%s), %d, %d, %d, %s, %s)';
            array_push($args, $row['hash'], $row['kind'], $row['version'], max(0, $postId), $stamp, $stamp);
        }

        $written = $this->db->execute(
            'INSERT IGNORE INTO %i (key_hash, key_kind, key_version, post_id, first_seen, last_seen) VALUES '
            . implode(', ', $values),
            $args
        );
        $written = max(0, min(count($rows), $written));

        if ($written < count($rows)) {
            $this->touch(array_column($rows, 'hash'), $time);
        }

        return $written;
    }

    /**
     * Moves last-seen forward to $time for rows that are older.
     *
     * @param list<string> $hashes
     *
     * @throws StoreException
     */
    public function touch(array $hashes, int $time): int
    {
        $hashes = self::validHashes($hashes);
        if ($hashes === []) {
            return 0;
        }

        $stamp = self::toDate($time);
        $in = implode(', ', array_fill(0, count($hashes), 'UNHEX(%s)'));

        return $this->db->execute(
            'UPDATE %i SET last_seen = %s WHERE key_hash IN (' . $in . ') AND last_seen < %s',
            array_merge([$this->name(), $stamp], $hashes, [$stamp])
        );
    }

    /**
     * @throws StoreException
     */
    public function deleteByPost(int $postId): int
    {
        if ($postId <= 0) {
            return 0;
        }
        return $this->db->execute('DELETE FROM %i WHERE post_id = %d', [$this->name(), $postId]);
    }

    /**
     * Deletes up to $limit rows last seen before $cutoff.
     *
     * @throws StoreException
     */
    public function deleteOlderThan(int $cutoff, int $limit): int
    {
        return $this->db->execute(
            'DELETE FROM %i WHERE last_seen < %s LIMIT %d',
            [$this->name(), self::toDate($cutoff), max(1, $limit)]
        );
    }

    /**
     * @throws StoreException
     */
    public function count(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM %i', [$this->name()]);
    }

    /**
     * Oldest and newest last-seen times, or nulls for an empty table.
     *
     * @return array{oldest: int|null, newest: int|null}
     *
     * @throws StoreException
     */
    public function lastSeenRange(): array
    {
        $rows = $this->db->rows('SELECT MIN(last_seen) AS oldest, MAX(last_seen) AS newest FROM %i', [$this->name()]);
        $row = $rows[0] ?? [];

        return [
            'oldest' => isset($row['oldest']) ? self::toTime($row['oldest']) : null,
            'newest' => isset($row['newest']) ? self::toTime($row['newest']) : null,
        ];
    }

    public static function toDate(int $time): string
    {
        return gmdate('Y-m-d H:i:s', $time);
    }

    public static function toTime(mixed $value): int
    {
        if (!is_string($value) || $value === '') {
            return 0;
        }
        $time = strtotime($value . ' UTC');

        return $time === false ? 0 : $time;
    }

    /**
     * @param list<string> $hashes
     * @return list<string>
     */
    private static function validHashes(array $hashes): array
    {
        $out = [];
        foreach ($hashes as $hash) {
            if (preg_match('/^[0-9a-f]{32}$/', $hash) === 1 && !in_array($hash, $out, true)) {
                $out[] = $hash;
            }
        }

        return $out;
    }
}
