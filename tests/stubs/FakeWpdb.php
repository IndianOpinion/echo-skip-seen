<?php

declare(strict_types=1);

/**
 * In-memory fake of the WordPress database layer.
 *
 * It understands exactly the statements the plugin issues (and nothing
 * else), keeps a log of every statement, and can simulate failures:
 * an error on statements that match a pattern, a column that a CREATE
 * silently skips, and an ALTER that fails.
 */
// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
class wpdb
{
    public const COLUMNS = ['key_hash', 'key_kind', 'key_version', 'post_id', 'first_seen', 'last_seen'];
    public const INDEXES = ['PRIMARY' => 'key_hash', 'post_id' => 'post_id', 'last_seen' => 'last_seen'];

    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $last_error = '';
    public int $rows_affected = 0;
    public int $num_queries = 0;
    public int $blogid = 1;

    /** @var list<string> */
    public array $log = [];

    /** @var array<string, array{columns: list<string>, indexes: array<string, string>, rows: array<string, array<string, mixed>>}> */
    public array $tables = [];

    /** Statements matching this pattern fail with an error. */
    public ?string $failPattern = null;

    /** Columns a CREATE TABLE silently leaves out (the dbDelta trap). */
    public array $skipColumnsOnCreate = [];

    public bool $alterFails = false;

    public bool $suppress = false;

    public function set_blog_id(int $id): void
    {
        $this->blogid = $id;
        $this->prefix = $this->get_blog_prefix($id);
        $this->posts = $this->prefix . 'posts';
    }

    public function get_blog_prefix(?int $id = null): string
    {
        $id = $id ?? $this->blogid;

        return $id <= 1 ? $this->base_prefix : $this->base_prefix . $id . '_';
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppress;
        $this->suppress = $suppress;

        return $previous;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;

        return (string) preg_replace_callback('/%%|%[sdfi]/', static function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $value = $args[$i++] ?? '';
            return match ($m[0]) {
                '%d' => (string) (int) $value,
                '%f' => (string) (float) $value,
                '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                default => "'" . addslashes((string) $value) . "'",
            };
        }, $query);
    }

    public function query(string $sql): int|bool
    {
        $result = $this->exec($sql);
        if ($result === false) {
            return false;
        }

        return is_int($result) ? $result : count($result);
    }

    public function get_results(string $sql, string $output = 'OBJECT'): ?array
    {
        $result = $this->exec($sql);
        if (!is_array($result)) {
            return null;
        }
        if ($output === 'ARRAY_A') {
            return $result;
        }

        return array_map(static fn (array $row): object => (object) $row, $result);
    }

    public function get_var(string $sql): ?string
    {
        $result = $this->exec($sql);
        if (!is_array($result) || $result === []) {
            return null;
        }
        $first = reset($result[0]);

        return $first === null ? null : (string) $first;
    }

    /**
     * Statements on the plugin table only (for counting in tests).
     *
     * @return list<string>
     */
    public function tableQueries(string $needle = 'echo_skip_seen'): array
    {
        return array_values(array_filter($this->log, static fn (string $q): bool => str_contains($q, $needle)));
    }

    public function createPluginTable(string $name): void
    {
        $columns = array_values(array_diff(self::COLUMNS, $this->skipColumnsOnCreate));
        $indexes = [];
        foreach (self::INDEXES as $index => $column) {
            if (in_array($column, $columns, true)) {
                $indexes[$index] = $column;
            }
        }
        $this->tables[$name] = ['columns' => $columns, 'indexes' => $indexes, 'rows' => []];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function rows(?string $table = null): array
    {
        $table = $table ?? $this->prefix . 'echo_skip_seen';

        return $this->tables[$table]['rows'] ?? [];
    }

    /**
     * @return list<array<string, mixed>>|int|false
     */
    private function exec(string $sql): array|int|false
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        $this->log[] = $sql;
        $this->num_queries++;
        $this->last_error = '';
        $this->rows_affected = 0;

        if ($this->failPattern !== null && preg_match($this->failPattern, $sql) === 1) {
            return $this->error('Simulated database error');
        }

        if (preg_match("/^SHOW TABLES LIKE '(.+)'$/", $sql, $m)) {
            $name = stripslashes(str_replace(['\\\\_', '\\_'], '_', $m[1]));
            $name = str_replace('\\', '', $name);

            return isset($this->tables[$name]) ? [['name' => $name]] : [];
        }

        if (preg_match('/^CREATE TABLE (IF NOT EXISTS )?`?([A-Za-z0-9_]+)`? \(/', $sql, $m)) {
            if (isset($this->tables[$m[2]])) {
                return $m[1] !== '' ? 0 : $this->error("Table '{$m[2]}' already exists");
            }
            $this->createPluginTable($m[2]);

            return 0;
        }

        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m)) {
            unset($this->tables[$m[1]]);

            return 0;
        }

        if (preg_match('/FROM `([^`]+)`/', $sql, $m) && str_ends_with($m[1], 'posts')) {
            return $this->postsQuery($sql);
        }

        if (!preg_match('/(?:FROM|INTO|TABLE|UPDATE) `([^`]+)`/', $sql, $m)) {
            return $this->error('Fake wpdb does not understand: ' . $sql);
        }
        $name = $m[1];
        if (!isset($this->tables[$name])) {
            return $this->error("Table '{$name}' doesn't exist");
        }
        $table = &$this->tables[$name];

        if (str_starts_with($sql, 'SHOW COLUMNS')) {
            return array_map(static fn (string $c): array => ['Field' => $c], $table['columns']);
        }
        if (str_starts_with($sql, 'SHOW INDEX')) {
            $out = [];
            foreach ($table['indexes'] as $index => $column) {
                $out[] = ['Key_name' => $index, 'Column_name' => $column];
            }

            return $out;
        }
        if (preg_match('/^ALTER TABLE `[^`]+` ADD COLUMN (\w+)/', $sql, $m)) {
            if ($this->alterFails) {
                return $this->error('ALTER command denied');
            }
            $table['columns'][] = $m[1];

            return 0;
        }
        if (preg_match('/^ALTER TABLE `[^`]+` ADD (PRIMARY KEY|KEY (\w+)) \((\w+)\)/', $sql, $m)) {
            if ($this->alterFails) {
                return $this->error('ALTER command denied');
            }
            $table['indexes'][$m[1] === 'PRIMARY KEY' ? 'PRIMARY' : $m[2]] = $m[3];

            return 0;
        }

        foreach (self::COLUMNS as $column) {
            if (str_contains($sql, $column) && !in_array($column, $table['columns'], true)) {
                return $this->error("Unknown column '{$column}'");
            }
        }

        if (str_starts_with($sql, 'SELECT LOWER(HEX(key_hash))')) {
            $out = [];
            foreach (self::hashes($sql) as $hash) {
                if (isset($table['rows'][$hash])) {
                    $row = $table['rows'][$hash];
                    $out[] = ['h' => $hash] + $row;
                }
            }

            return $out;
        }

        if (str_starts_with($sql, 'INSERT IGNORE INTO')) {
            preg_match_all("/\(UNHEX\('([0-9a-f]{32})'\), (\d+), (\d+), (\d+), '([^']*)', '([^']*)'\)/", $sql, $all, PREG_SET_ORDER);
            $written = 0;
            foreach ($all as $t) {
                if (isset($table['rows'][$t[1]])) {
                    continue;
                }
                $table['rows'][$t[1]] = [
                    'key_kind' => (int) $t[2],
                    'key_version' => (int) $t[3],
                    'post_id' => (int) $t[4],
                    'first_seen' => $t[5],
                    'last_seen' => $t[6],
                ];
                $written++;
            }
            $this->rows_affected = $written;

            return $written;
        }

        if (preg_match("/^UPDATE `[^`]+` SET last_seen = '([^']*)' WHERE key_hash IN \(.*\) AND last_seen < '([^']*)'$/", $sql, $m)) {
            $count = 0;
            foreach (self::hashes($sql) as $hash) {
                if (isset($table['rows'][$hash]) && $table['rows'][$hash]['last_seen'] < $m[2]) {
                    $table['rows'][$hash]['last_seen'] = $m[1];
                    $count++;
                }
            }
            $this->rows_affected = $count;

            return $count;
        }

        if (preg_match('/^DELETE FROM `[^`]+` WHERE post_id = (\d+)$/', $sql, $m)) {
            $count = 0;
            foreach ($table['rows'] as $hash => $row) {
                if ($row['post_id'] === (int) $m[1]) {
                    unset($table['rows'][$hash]);
                    $count++;
                }
            }
            $this->rows_affected = $count;

            return $count;
        }

        if (preg_match("/^DELETE FROM `[^`]+` WHERE last_seen < '([^']*)' LIMIT (\d+)$/", $sql, $m)) {
            $count = 0;
            foreach ($table['rows'] as $hash => $row) {
                if ($count >= (int) $m[2]) {
                    break;
                }
                if ($row['last_seen'] < $m[1]) {
                    unset($table['rows'][$hash]);
                    $count++;
                }
            }
            $this->rows_affected = $count;

            return $count;
        }

        if (str_starts_with($sql, 'SELECT COUNT(*) FROM')) {
            return [['count' => count($table['rows'])]];
        }

        if (str_starts_with($sql, 'SELECT MIN(last_seen)')) {
            $seen = array_column($table['rows'], 'last_seen');

            return [['oldest' => $seen === [] ? null : min($seen), 'newest' => $seen === [] ? null : max($seen)]];
        }

        return $this->error('Fake wpdb does not understand: ' . $sql);
    }

    /**
     * @return list<array<string, mixed>>|false
     */
    private function postsQuery(string $sql): array|false
    {
        if (!preg_match("/WHERE ID > (\d+) AND post_type IN \(([^)]*)\) AND post_status <> 'auto-draft'(?: AND post_date_gmt >= '([^']*)')?/", $sql, $m)) {
            return $this->error('Fake wpdb does not understand posts query: ' . $sql);
        }
        $after = (int) $m[1];
        $types = array_map(static fn (string $t): string => trim($t, " '"), explode(',', $m[2]));
        $since = $m[3] ?? '';

        $rows = [];
        foreach (WpStubs::posts() as $post) {
            if ($post->ID <= $after || !in_array($post->post_type, $types, true) || $post->post_status === 'auto-draft') {
                continue;
            }
            if ($since !== '' && $post->post_date_gmt < $since) {
                continue;
            }
            $rows[] = ['ID' => (string) $post->ID, 'post_date' => $post->post_date, 'post_date_gmt' => $post->post_date_gmt];
        }
        usort($rows, static fn (array $a, array $b): int => (int) $a['ID'] <=> (int) $b['ID']);

        if (str_starts_with($sql, 'SELECT COUNT(*)')) {
            return [['count' => count($rows)]];
        }
        if (preg_match('/LIMIT (\d+)$/', $sql, $l)) {
            $rows = array_slice($rows, 0, (int) $l[1]);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private static function hashes(string $sql): array
    {
        preg_match_all("/UNHEX\('([0-9a-f]{32})'\)/", $sql, $m);

        return $m[1];
    }

    /**
     * @return false
     */
    private function error(string $message): bool
    {
        $this->last_error = $message;

        return false;
    }
}
