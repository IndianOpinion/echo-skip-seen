<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use WP_Site;

/**
 * Activation, deactivation, multisite site events and the init check.
 */
final class Installer
{
    /** Sites handled per get_sites() call during network activation. */
    public const SITE_BATCH = 100;

    public function __construct(
        private readonly Schema $schema,
        private readonly KeyVersions $versions,
        private readonly string $pluginBasename,
    ) {
    }

    /**
     * Activation hook. Creates the table on this site or, for a network
     * activation, on every site.
     */
    public function activate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            self::forEachSite(fn () => $this->installSite());

            return;
        }

        $this->installSite();
    }

    /**
     * Deactivation hook. Keeps the data; removes the scheduled event.
     */
    public function deactivate(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            self::forEachSite(static fn () => wp_clear_scheduled_hook(Retention::HOOK));

            return;
        }

        wp_clear_scheduled_hook(Retention::HOOK);
    }

    /**
     * Runs on init for every request type: creates the table when the
     * stored schema version is missing or old, syncs the key version, and
     * schedules the daily prune when it is missing.
     */
    public function onInit(): void
    {
        try {
            $this->schema->maybeInstall();
            $this->versions->sync();
            self::ensureSchedule();
        } catch (\Throwable) {
            // Never break a request; lookups fail open until the schema is ready.
            return;
        }
    }

    public function installSite(): void
    {
        if (get_option(Settings::OPTION, null) === null) {
            add_option(Settings::OPTION, Settings::defaults(), '', false);
        }
        $this->schema->install();
        $this->versions->sync();
        self::ensureSchedule();
    }

    /**
     * wp_initialize_site: a new site in a network where the plugin is network active.
     */
    public function onInitializeSite(mixed $site): void
    {
        if (!$site instanceof WP_Site || !$this->isNetworkActive()) {
            return;
        }

        switch_to_blog((int) $site->blog_id);
        try {
            $this->installSite();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * wpmu_drop_tables: drop this plugin's table with the site.
     *
     * @param array<mixed> $tables
     * @return array<mixed>
     */
    public function dropTables(mixed $tables, mixed $siteId = 0): array
    {
        global $wpdb;
        $tables = is_array($tables) ? $tables : [];
        $prefix = $wpdb->get_blog_prefix(is_numeric($siteId) ? (int) $siteId : 0);
        $tables[Table::SUFFIX] = Table::nameForPrefix($prefix);

        return $tables;
    }

    public static function ensureSchedule(): void
    {
        if (wp_next_scheduled(Retention::HOOK) === false) {
            wp_schedule_event(time() + 600, 'daily', Retention::HOOK);
        }
    }

    /**
     * Runs a callback on every site of a network, in batches.
     *
     * @param callable(): mixed $callback
     */
    public static function forEachSite(callable $callback): void
    {
        $offset = 0;
        do {
            $ids = get_sites([
                'fields' => 'ids',
                'number' => self::SITE_BATCH,
                'offset' => $offset,
                'orderby' => 'id',
            ]);
            $ids = is_array($ids) ? $ids : [];
            foreach ($ids as $id) {
                switch_to_blog((int) $id);
                try {
                    $callback();
                } finally {
                    restore_current_blog();
                }
            }
            $offset += self::SITE_BATCH;
        } while (count($ids) === self::SITE_BATCH);
    }

    private function isNetworkActive(): bool
    {
        if (!function_exists('is_plugin_active_for_network') && defined('ABSPATH')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($this->pluginBasename);
    }
}
