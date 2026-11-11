<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Admin\AdminPage;
use IndianOpinion\EchoSkipSeen\Backfill;
use IndianOpinion\EchoSkipSeen\Cli\Command;
use IndianOpinion\EchoSkipSeen\Counters;
use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\KeyVersions;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Retention;
use IndianOpinion\EchoSkipSeen\Settings;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use IndianOpinion\EchoSkipSeen\Uninstaller;
use WpCliError;
use WpStubs;

/**
 * PRD 4.11, acceptance tests T63 to T67 and T71 (verify-schema).
 */
final class SchemaTest extends PluginTestCase
{
    public function testFreshInstallVerifiesBeforeStoringVersion(): void
    {
        // T63.
        WpStubs::reset();
        $plugin = $this->newRequest();
        self::assertFalse($plugin->schema()->isReady());
        $plugin->activate(false);

        $log = $this->wpdb()->log;
        $create = self::indexOf($log, 'CREATE TABLE');
        $columns = self::indexOf($log, 'SHOW COLUMNS');
        $indexes = self::indexOf($log, 'SHOW INDEX');
        self::assertGreaterThan($create, $columns);
        self::assertGreaterThan($columns, $indexes);
        self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        self::assertTrue(WpStubs::$autoload[1][Schema::VERSION_OPTION]);
        self::assertFalse(WpStubs::$autoload[1][Settings::OPTION]);
        self::assertTrue(get_option(Schema::CHECK_OPTION)['ok']);
    }

    public function testSilentlySkippedColumnIsRepaired(): void
    {
        // T64, first half.
        WpStubs::reset();
        $this->wpdb()->skipColumnsOnCreate = ['key_kind'];
        $plugin = $this->newRequest();
        $plugin->activate(false);
        self::assertTrue($plugin->schema()->isReady());
        self::assertContains('key_kind', $this->wpdb()->tables['wp_echo_skip_seen']['columns']);
        self::assertNotEmpty(array_filter($this->wpdb()->log, static fn (string $q): bool => str_contains($q, 'ADD COLUMN key_kind')));
    }

    public function testFailedRepairLeavesVersionAndFailsOpen(): void
    {
        // T64, second half.
        WpStubs::reset();
        $this->wpdb()->skipColumnsOnCreate = ['last_seen'];
        $this->wpdb()->alterFails = true;
        $plugin = $this->newRequest();
        $plugin->activate(false);

        self::assertFalse($plugin->schema()->isReady());
        self::assertFalse(get_option(Schema::VERSION_OPTION, false));
        $check = get_option(Schema::CHECK_OPTION);
        self::assertFalse($check['ok']);
        self::assertContains('column last_seen', $check['missing']);
        self::assertContains('index last_seen', $check['missing']);

        ob_start();
        (new AdminPage($plugin))->schemaNotice();
        $notice = (string) ob_get_clean();
        self::assertStringContainsString('column last_seen', $notice);

        self::assertSame('https://example.com/a', $this->dup('https://example.com/a'));
        self::assertFalse(echo_skip_seen_record('https://example.com/a', 1));
        self::assertSame(2, $this->counter('failed_open'));
    }

    public function testInitRetriesAtMostHourlyAfterFailure(): void
    {
        WpStubs::reset();
        $this->wpdb()->failPattern = '/^CREATE TABLE/';
        $plugin = $this->newRequest();
        do_action('init');
        $creates = static fn (): int => count(array_filter($GLOBALS['wpdb']->log, static fn (string $q): bool => str_starts_with($q, 'CREATE TABLE')));
        self::assertSame(2, $creates(), 'dbDelta and the explicit repair both tried');
        do_action('init');
        self::assertSame(2, $creates());
        $this->clock->advance(Schema::RETRY_SECONDS + 1);
        $this->wpdb()->failPattern = null;
        do_action('init');
        self::assertTrue($plugin->schema()->isReady());
        self::assertFalse(get_transient(Schema::LOCK_TRANSIENT));
    }

    public function testInitSkipsWhileLocked(): void
    {
        WpStubs::reset();
        $plugin = $this->newRequest();
        set_transient(Schema::LOCK_TRANSIENT, 1, 60);
        do_action('init');
        self::assertFalse($plugin->schema()->isReady());
        self::assertSame([], $this->wpdb()->log);
    }

    public function testLookupRunsNoSchemaQuery(): void
    {
        // T65.
        echo_skip_seen_record('https://example.com/a', 1);
        $this->newRequest();
        $this->wpdb()->log = [];
        do_action('init');
        $this->dup('https://example.com/a');
        $this->dup('https://example.com/b');
        foreach ($this->wpdb()->log as $query) {
            self::assertStringStartsNotWith('SHOW', $query);
            self::assertStringStartsNotWith('CREATE', $query);
        }
        self::assertCount(2, $this->wpdb()->log);
    }

    public function testKeyVersionWindow(): void
    {
        // T66.
        $this->plugin->settings()->save(['retention_days' => 30]);
        do_action('init');
        echo_skip_seen_record('https://m.example.com/v1/', 1);
        $plain = md5('m.example.com/v1');

        // Version 2 ships: version 1 rows still match within one retention period.
        $this->newRequest(2);
        do_action('init');
        self::assertSame(['current' => 2, 'previous' => 1], array_intersect_key(get_option(KeyVersions::OPTION), ['current' => 1, 'previous' => 1]));
        self::assertTrue($this->dup('https://example.com/v1'));

        // Without the plain row, the canonical row of version 1 still matches.
        unset($this->wpdb()->tables['wp_echo_skip_seen']['rows'][$plain]);
        $this->newRequest(2);
        self::assertTrue($this->dup('https://example.com/v1'));

        // After the window, version 1 rows no longer match.
        $this->clock->advance(31 * 86400);
        $hash = md5('example.com/v1');
        $this->wpdb()->tables['wp_echo_skip_seen']['rows'][$hash]['last_seen'] = Table::toDate($this->clock->now());
        $this->newRequest(2);
        self::assertSame('https://example.com/v1', $this->dup('https://example.com/v1'));

        // Version 3: rows two versions old never match, plain rows always do.
        $this->newRequest(3);
        do_action('init');
        self::assertSame('https://example.com/v1', $this->dup('https://example.com/v1'));
        $this->wpdb()->tables['wp_echo_skip_seen']['rows'][$plain] = [
            'key_kind' => KeySet::KIND_PLAIN,
            'key_version' => 0,
            'post_id' => 1,
            'first_seen' => Table::toDate($this->clock->now()),
            'last_seen' => Table::toDate($this->clock->now()),
        ];
        $this->newRequest(3);
        self::assertTrue($this->dup('https://m.example.com/v1/'));
    }

    public function testUninstallRemovesEverythingOnEverySite(): void
    {
        // T67.
        WpStubs::reset();
        WpStubs::$multisite = true;
        WpStubs::$sites = [1, 2, 3];
        $plugin = $this->newRequest();
        $plugin->activate(true);
        switch_to_blog(2);
        $plugin->settings()->flush();
        $plugin->settings()->save(['meta_keys' => ['k']]);
        $plugin->counters()->increment('checked');
        $plugin->counters()->flush();
        update_option(Backfill::CURSOR_OPTION, ['last_id' => 1]);
        restore_current_blog();
        self::assertCount(3, $this->wpdb()->tables);

        Uninstaller::run();

        self::assertSame([], $this->wpdb()->tables);
        foreach ([1, 2, 3] as $site) {
            foreach (Uninstaller::OPTIONS as $option) {
                self::assertArrayNotHasKey($option, WpStubs::$options[$site] ?? [], "site {$site} {$option}");
            }
            self::assertArrayNotHasKey(Retention::HOOK, WpStubs::$cron[$site] ?? []);
        }
        self::assertContains(Counters::OPTION, Uninstaller::OPTIONS);
    }

    public function testUninstallScriptGuard(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        self::assertStringContainsString("defined('WP_UNINSTALL_PLUGIN')", $source);
    }

    public function testVerifySchemaCommand(): void
    {
        // T71.
        $command = new Command($this->plugin);
        $command->verifySchema([], []);
        self::assertStringStartsWith('Success: Schema ok', (string) end(WpStubs::$cliOutput));

        $this->wpdb()->tables['wp_echo_skip_seen']['columns'] = array_values(array_diff(
            $this->wpdb()->tables['wp_echo_skip_seen']['columns'],
            ['post_id']
        ));
        $this->wpdb()->alterFails = true;
        try {
            $command->verifySchema([], []);
            self::fail('verify-schema should fail');
        } catch (WpCliError $e) {
            self::assertStringContainsString('column post_id', $e->getMessage());
        }
    }

    public function testDropTablesFilterForDeletedSite(): void
    {
        $tables = apply_filters('wpmu_drop_tables', ['wp_5_posts'], 5);
        self::assertContains('wp_5_' . Table::SUFFIX, $tables);
    }

    /**
     * @param list<string> $log
     */
    private static function indexOf(array $log, string $prefix): int
    {
        foreach ($log as $index => $query) {
            if (str_starts_with($query, $prefix)) {
                return $index;
            }
        }
        self::fail('no statement starting with ' . $prefix);
    }
}
