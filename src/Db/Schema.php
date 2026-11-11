<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Db;

use IndianOpinion\EchoSkipSeen\Clock;
use IndianOpinion\EchoSkipSeen\Exception\StoreException;

/**
 * Creates, verifies and repairs the table (PRD 4.11).
 *
 * The stored schema version is written only after the real column and
 * index lists, read back from the database, match what the code expects.
 * dbDelta has silently skipped column additions in production, so its
 * result is never trusted on its own.
 */
final class Schema
{
    public const VERSION = 1;

    /** Autoloaded: read on every request by the init check, so it must cost no query. */
    public const VERSION_OPTION = 'echo_skip_seen_db_version';

    /** Result of the last check: ok, missing parts, time. Not autoloaded. */
    public const CHECK_OPTION = 'echo_skip_seen_schema_check';

    public const LOCK_TRANSIENT = 'echo_skip_seen_install_lock';

    public const LOCK_SECONDS = 60;

    /** After a failed install, init waits this long before trying again. */
    public const RETRY_SECONDS = 3600;

    private const COLUMNS = [
        'key_hash' => 'key_hash binary(16) NOT NULL',
        'key_kind' => 'key_kind tinyint(3) unsigned NOT NULL DEFAULT 1',
        'key_version' => 'key_version smallint(5) unsigned NOT NULL DEFAULT 0',
        'post_id' => 'post_id bigint(20) unsigned NOT NULL DEFAULT 0',
        'first_seen' => 'first_seen datetime NOT NULL',
        'last_seen' => 'last_seen datetime NOT NULL',
    ];

    /** Index name to [column, ALTER clause]. */
    private const INDEXES = [
        'PRIMARY' => ['key_hash', 'ADD PRIMARY KEY (key_hash)'],
        'post_id' => ['post_id', 'ADD KEY post_id (post_id)'],
        'last_seen' => ['last_seen', 'ADD KEY last_seen (last_seen)'],
    ];

    public function __construct(
        private readonly Db $db,
        private readonly Table $table,
        private readonly Clock $clock,
    ) {
    }

    /**
     * True when the stored schema version is the code's. Costs no query
     * once options are loaded (the option is autoloaded).
     */
    public function isReady(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) === self::VERSION;
    }

    /**
     * Called on init. Installs when the stored version is missing or old,
     * under a short lock, and not more than once an hour after a failure.
     */
    public function maybeInstall(): void
    {
        if ($this->isReady()) {
            return;
        }

        $check = $this->lastCheck();
        if ($check !== null && !$check['ok'] && $this->clock->now() - $check['time'] < self::RETRY_SECONDS) {
            return;
        }

        if (get_transient(self::LOCK_TRANSIENT) !== false) {
            return;
        }
        set_transient(self::LOCK_TRANSIENT, $this->clock->now(), self::LOCK_SECONDS);

        try {
            $this->install();
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    /**
     * Creates or upgrades the table, verifies it, repairs what is missing,
     * verifies again, and records the result.
     *
     * @return list<string> Missing parts; empty means the schema is good.
     */
    public function install(): array
    {
        try {
            $this->db->quietly(function (): void {
                if (!function_exists('dbDelta') && defined('ABSPATH')) {
                    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                }
                if (function_exists('dbDelta')) {
                    dbDelta($this->createSql());
                }
            });

            $missing = $this->verify();
            if ($missing !== []) {
                $this->repair($missing);
                $missing = $this->verify();
            }
        } catch (StoreException $e) {
            $missing = ['error: ' . $e->getMessage()];
        }

        $this->record($missing);

        return $missing;
    }

    /**
     * Reads the real columns and indexes and lists what is missing.
     *
     * @return list<string> Entries like "table", "column key_kind", "index last_seen".
     *
     * @throws StoreException
     */
    public function verify(): array
    {
        if (!$this->exists()) {
            return ['table'];
        }

        $table = $this->table->name();
        $columns = [];
        foreach ($this->db->rows('SHOW COLUMNS FROM %i', [$table]) as $row) {
            if (isset($row['Field']) && is_string($row['Field'])) {
                $columns[strtolower($row['Field'])] = true;
            }
        }

        $indexes = [];
        foreach ($this->db->rows('SHOW INDEX FROM %i', [$table]) as $row) {
            if (isset($row['Key_name'], $row['Column_name']) && is_string($row['Key_name'])) {
                $indexes[$row['Key_name']][] = strtolower((string) $row['Column_name']);
            }
        }

        $missing = [];
        foreach (array_keys(self::COLUMNS) as $column) {
            if (!isset($columns[$column])) {
                $missing[] = 'column ' . $column;
            }
        }
        foreach (self::INDEXES as $name => [$column]) {
            if (!in_array($column, $indexes[$name] ?? [], true)) {
                $missing[] = 'index ' . $name;
            }
        }

        return $missing;
    }

    public function exists(): bool
    {
        try {
            $name = $this->table->name();

            return $this->db->value('SHOW TABLES LIKE %s', [$this->db->wpdb()->esc_like($name)]) === $name;
        } catch (StoreException) {
            return false;
        }
    }

    /**
     * Drops the table of the current site.
     *
     * @throws StoreException
     */
    public function drop(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS %i', [$this->table->name()]);
    }

    public function createSql(): string
    {
        $table = $this->table->name();
        $columns = implode(",\n  ", self::COLUMNS);

        // dbDelta wants two spaces after PRIMARY KEY and one definition per line.
        return "CREATE TABLE {$table} (\n  {$columns},\n  PRIMARY KEY  (key_hash),\n"
            . "  KEY post_id (post_id),\n  KEY last_seen (last_seen)\n) " . $this->db->charsetCollate() . ';';
    }

    /**
     * @return array{ok: bool, missing: list<string>, time: int}|null
     */
    public function lastCheck(): ?array
    {
        $check = get_option(self::CHECK_OPTION, null);
        if (!is_array($check) || !isset($check['ok'], $check['time'])) {
            return null;
        }

        $missing = [];
        foreach ((array) ($check['missing'] ?? []) as $item) {
            if (is_string($item)) {
                $missing[] = $item;
            }
        }

        return ['ok' => (bool) $check['ok'], 'missing' => $missing, 'time' => (int) $check['time']];
    }

    /**
     * @param list<string> $missing
     *
     * @throws StoreException
     */
    private function repair(array $missing): void
    {
        $table = $this->table->name();

        if (in_array('table', $missing, true)) {
            $this->db->executeDdl(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $this->createSql()));

            return;
        }

        foreach ($missing as $item) {
            [$type, $name] = explode(' ', $item, 2) + [1 => ''];
            try {
                if ($type === 'column' && isset(self::COLUMNS[$name])) {
                    $this->db->execute('ALTER TABLE %i ADD COLUMN ' . self::COLUMNS[$name], [$table]);
                } elseif ($type === 'index' && isset(self::INDEXES[$name])) {
                    $this->db->execute('ALTER TABLE %i ' . self::INDEXES[$name][1], [$table]);
                }
            } catch (StoreException) {
                // The second verify reports what is still missing.
                continue;
            }
        }
    }

    /**
     * @param list<string> $missing
     */
    private function record(array $missing): void
    {
        $ok = $missing === [];
        $check = ['ok' => $ok, 'missing' => $missing, 'time' => $this->clock->now()];
        if (get_option(self::CHECK_OPTION, null) === null) {
            add_option(self::CHECK_OPTION, $check, '', false);
        } else {
            update_option(self::CHECK_OPTION, $check, false);
        }

        if ($ok) {
            update_option(self::VERSION_OPTION, self::VERSION, true);
        }
    }
}
