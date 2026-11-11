<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Db;

use IndianOpinion\EchoSkipSeen\Exception\StoreException;
use wpdb;

// This class is the prepare() boundary: every statement with a value is
// prepared in prepare() below before run() executes it.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared

/**
 * The only place the plugin talks to $wpdb.
 *
 * Every statement with a value goes through $wpdb->prepare(). Errors are
 * read from $wpdb->last_error after each statement and turned into a
 * StoreException. Printing of database errors is suppressed for the
 * duration of the statement, so a broken table never emits output.
 */
final class Db
{
    public function __construct(private readonly wpdb $wpdb)
    {
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    public function prefix(): string
    {
        return $this->wpdb->prefix;
    }

    public function postsTable(): string
    {
        return $this->wpdb->posts;
    }

    public function charsetCollate(): string
    {
        return $this->wpdb->get_charset_collate();
    }

    /**
     * Runs a statement and returns the number of affected rows.
     *
     * @param literal-string   $sql  Template; the table name goes in as an %i argument.
     * @param list<string|int> $args
     *
     * @throws StoreException
     */
    public function execute(string $sql, array $args = []): int
    {
        $this->run(fn (string $q): mixed => $this->wpdb->query($q), $this->prepare($sql, $args));

        return (int) $this->wpdb->rows_affected;
    }

    /**
     * Runs a DDL statement that carries no values: it is built only from
     * the site's table prefix, the charset clause and class constants.
     *
     * @throws StoreException
     */
    public function executeDdl(string $sql): int
    {
        $this->run(fn (string $q): mixed => $this->wpdb->query($q), $sql);

        return (int) $this->wpdb->rows_affected;
    }

    /**
     * @param literal-string   $sql
     * @param list<string|int> $args
     * @return list<array<string, mixed>>
     *
     * @throws StoreException
     */
    public function rows(string $sql, array $args = []): array
    {
        $result = $this->run(fn (string $q): mixed => $this->wpdb->get_results($q, ARRAY_A), $this->prepare($sql, $args));
        if (!is_array($result)) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param literal-string   $sql
     * @param list<string|int> $args
     *
     * @throws StoreException
     */
    public function value(string $sql, array $args = []): ?string
    {
        $result = $this->run(fn (string $q): mixed => $this->wpdb->get_var($q), $this->prepare($sql, $args));

        return $result === null ? null : (is_scalar($result) ? (string) $result : null);
    }

    /**
     * Runs a callback (such as dbDelta) with database error output suppressed.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     *
     * @throws StoreException
     */
    public function quietly(callable $callback): mixed
    {
        $previous = $this->wpdb->suppress_errors(true);
        try {
            return $callback();
        } finally {
            $this->wpdb->suppress_errors($previous);
        }
    }

    /**
     * @param literal-string   $sql
     * @param list<string|int> $args
     *
     * @throws StoreException
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            return $sql;
        }

        $prepared = $this->wpdb->prepare($sql, ...$args);
        if (!is_string($prepared) || $prepared === '') {
            throw new StoreException('could not prepare statement');
        }

        return $prepared;
    }

    /**
     * @param callable(string): mixed $call
     *
     * @throws StoreException
     */
    private function run(callable $call, string $sql): mixed
    {
        $previous = $this->wpdb->suppress_errors(true);
        try {
            $this->wpdb->last_error = '';
            $result = $call($sql);
        } catch (\Throwable $e) {
            throw new StoreException('database call threw ' . get_class($e), 0, $e);
        } finally {
            $this->wpdb->suppress_errors($previous);
        }

        $error = $this->lastError();
        if ($error !== '') {
            throw new StoreException('database error: ' . substr($error, 0, 200));
        }
        if ($result === false) {
            throw new StoreException('database call failed');
        }

        return $result;
    }

    private function lastError(): string
    {
        return (string) $this->wpdb->last_error;
    }
}
