<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Db;
use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;

/**
 * Removes everything the plugin created, on every site (PRD 100).
 */
final class Uninstaller
{
    public const OPTIONS = [
        Settings::OPTION,
        Counters::OPTION,
        Schema::VERSION_OPTION,
        Schema::CHECK_OPTION,
        Backfill::CURSOR_OPTION,
        KeyVersions::OPTION,
    ];

    public static function run(): void
    {
        if (is_multisite()) {
            Installer::forEachSite(static fn () => self::cleanSite());

            return;
        }

        self::cleanSite();
    }

    public static function cleanSite(): void
    {
        global $wpdb;
        $db = new Db($wpdb);
        $table = new Table($db);
        try {
            (new Schema($db, $table, new SystemClock()))->drop();
        } catch (\Throwable) {
            // Keep going: options and events are still removed.
            $table = null;
        }

        foreach (self::OPTIONS as $option) {
            delete_option($option);
        }
        delete_transient(Schema::LOCK_TRANSIENT);
        wp_clear_scheduled_hook(Retention::HOOK);
    }
}
