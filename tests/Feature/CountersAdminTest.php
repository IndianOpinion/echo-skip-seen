<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Admin\AdminPage;
use IndianOpinion\EchoSkipSeen\Admin\SiteHealth;
use IndianOpinion\EchoSkipSeen\Cli\Command;
use IndianOpinion\EchoSkipSeen\Counters;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use WpDieException;
use WpStubs;

/**
 * PRD 4.12, 8 and 11, acceptance tests T68 to T71, plus Site Health.
 */
final class CountersAdminTest extends PluginTestCase
{
    public function testOneCounterWritePerRequest(): void
    {
        // T68.
        echo_skip_seen_record('https://example.com/seen', 1);
        $this->newRequest();
        $before = WpStubs::writes(Counters::OPTION);
        for ($i = 0; $i < 500; $i++) {
            $this->dup('https://example.com/item-' . ($i % 250));
        }
        $this->dup('https://example.com/seen');
        self::assertSame($before, WpStubs::writes(Counters::OPTION), 'nothing written before shutdown');
        self::assertSame(1, stub_callback_count('shutdown'));
        do_action('shutdown');
        self::assertSame($before + 1, WpStubs::writes(Counters::OPTION));
        $stored = get_option(Counters::OPTION);
        self::assertSame(501, $stored['counts']['checked']);
        self::assertSame(1, $stored['counts']['skipped']);
    }

    public function testResetNeedsNonceAndCapabilityAndFilterMatchesCli(): void
    {
        // T69.
        echo_skip_seen_record('https://example.com/seen', 1);
        $this->dup('https://example.com/seen');
        $this->plugin->counters()->flush();

        $page = new AdminPage($this->plugin, static function (): void {
        });
        try {
            $page->handleReset();
            self::fail('reset without nonce');
        } catch (WpDieException) {
            self::assertSame(1, $this->counter('skipped'));
        }
        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce(AdminPage::ACTION_RESET);
        WpStubs::$caps = [];
        try {
            $page->handleReset();
            self::fail('reset without capability');
        } catch (WpDieException) {
            self::assertSame(1, $this->counter('skipped'));
        }

        $filtered = apply_filters('echo_skip_seen_counters', []);
        (new Command($this->plugin))->stats([], ['format' => 'json']);
        self::assertEquals($filtered, json_decode((string) end(WpStubs::$cliOutput), true));
        self::assertSame(1, $filtered['skipped']);
        self::assertSame(1, $filtered['checked']);
        self::assertSame(1.0, $filtered['skip_rate']);

        WpStubs::$caps = ['manage_options' => true];
        $page->handleReset();
        self::assertSame(0, $this->counter('skipped'));
        self::assertSame($this->clock->now(), $this->plugin->counters()->snapshot()['since']['skipped']);

        $this->dup('https://example.com/seen');
        (new Command($this->plugin))->stats([], ['reset' => true]);
        self::assertSame(0, $this->counter('skipped'));
    }

    public function testRenderEscapesEverything(): void
    {
        // T70: a meta key with markup from a filter is printed escaped.
        add_filter('echo_skip_seen_source_meta_keys', static fn (): array => ['<script>alert(1)</script>']);
        echo_skip_seen_record('https://example.com/a', 1);
        $_POST = [
            'echo_skip_seen_check_nonce' => wp_create_nonce(AdminPage::NONCE_CHECK),
            'echo_skip_seen_check_url' => 'https://example.com/a?"><script>x</script>',
        ];
        ob_start();
        (new AdminPage($this->plugin))->render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Canonical key', $html);
        self::assertStringContainsString('Rows', $html);
    }

    public function testRenderRequiresCapability(): void
    {
        WpStubs::$caps = [];
        $this->expectException(WpDieException::class);
        (new AdminPage($this->plugin))->render();
    }

    public function testRenderWarnsWhenNoMetaKey(): void
    {
        $this->plugin->settings()->save(['meta_keys' => []]);
        ob_start();
        (new AdminPage($this->plugin))->render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('No meta key is configured', $html);
        self::assertStringContainsString('id="ess-backfill" disabled', $html);
    }

    public function testCheckCommandPrintsKeysHashesAndPresence(): void
    {
        // T71.
        echo_skip_seen_record('https://m.example.com/a/', 7);
        (new Command($this->plugin))->check(['https://example.com/a?utm_source=x'], []);
        $out = implode("\n", WpStubs::$cliOutput);
        self::assertStringContainsString("canonical key\texample.com/a", $out);
        self::assertStringContainsString("canonical hash\t" . md5('example.com/a'), $out);
        self::assertStringContainsString("canonical row\tpresent: post 7", $out);
        self::assertStringContainsString("plain key\texample.com/a?utm_source=x", $out);
        self::assertStringContainsString("plain row\tno", $out);
        self::assertStringContainsString("lookup would skip\tyes", $out);
    }

    public function testRecordAndPruneCommands(): void
    {
        $command = new Command($this->plugin);
        $command->record(['https://example.com/cli'], ['post-id' => '4']);
        self::assertSame('Success: 1 new row(s) written.', end(WpStubs::$cliOutput));
        $command->prune([], []);
        self::assertSame('Success: 0 expired row(s) deleted.', end(WpStubs::$cliOutput));
    }

    public function testSiteHealth(): void
    {
        $health = new SiteHealth($this->plugin);
        $tests = $health->addTest([]);
        self::assertArrayHasKey(SiteHealth::TEST, $tests['direct']);

        echo_skip_seen_record('https://example.com/a', 1);
        $result = $health->run();
        self::assertSame('good', $result['status']);
        self::assertStringContainsString('1 rows', $result['description']);
        self::assertStringNotContainsString('For information', $result['description']);

        for ($i = 0; $i < 30; $i++) {
            $this->dup('https://example.com/a');
        }
        $result = $health->run();
        self::assertSame('good', $result['status']);
        self::assertStringContainsString('For information: 100.0%', $result['description']);

        $this->wpdb()->tables = [];
        $result = $health->run();
        self::assertSame('critical', $result['status']);
    }

    public function testSiteHealthRecommendsMetaKeyOnEmptyTable(): void
    {
        $this->plugin->settings()->save(['meta_keys' => []]);
        $result = (new SiteHealth($this->plugin))->run();
        self::assertSame('recommended', $result['status']);
    }

    public function testSiteHealthOldChecksFallOutOfTheWindow(): void
    {
        echo_skip_seen_record('https://example.com/a', 1);
        for ($i = 0; $i < 30; $i++) {
            $this->dup('https://example.com/a');
        }
        $this->plugin->counters()->flush();
        $this->clock->advance(25 * 3600);
        $this->newRequest();
        $snapshot = $this->plugin->counters()->snapshot();
        self::assertSame(['checked' => 0, 'skipped' => 0], $snapshot['last_24h']);
        self::assertSame(30, $snapshot['skipped']);
    }

    public function testAllSqlGoesThroughTheWrapper(): void
    {
        // T70 static check: only Db.php calls the query methods of $wpdb,
        // and it prepares every statement that carries a value.
        $root = dirname(__DIR__, 2) . '/src';
        $offenders = [];
        foreach (FailOpenTest::phpFiles($root) as $file) {
            if (str_ends_with($file, '/Db/Db.php')) {
                continue;
            }
            $source = (string) file_get_contents($file);
            if (preg_match('/wpdb->(query|get_results|get_var|get_row|get_col|prepare)\(/', $source) === 1) {
                $offenders[] = $file;
            }
            if (preg_match('/\$wpdb->(?!get_blog_prefix|prefix|posts)/', $source) === 1) {
                $offenders[] = $file;
            }
        }
        self::assertSame([], $offenders);

        $db = (string) file_get_contents($root . '/Db/Db.php');
        self::assertStringContainsString('$this->wpdb->prepare($sql, ...$args)', $db);
    }
}
