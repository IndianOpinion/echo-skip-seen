<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Admin\AdminPage;
use IndianOpinion\EchoSkipSeen\Backfill;
use IndianOpinion\EchoSkipSeen\BackfillRequest;
use IndianOpinion\EchoSkipSeen\Cli\Command;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use WpCliError;
use WpDieException;
use WpJsonResponse;
use WpStubs;

/**
 * PRD 4.8, acceptance tests T53 to T57.
 */
final class BackfillTest extends PluginTestCase
{
    private function fixtures(int $count, ?string $date = null): void
    {
        $date ??= Table::toDate($this->clock->now() - 86400);
        for ($i = 1; $i <= $count; $i++) {
            WpStubs::addPostQuietly(['post_date_gmt' => $date], [self::META_KEY => 'https://m.example.com/story-' . $i . '/']);
        }
    }

    private function cli(): Command
    {
        return new Command($this->plugin);
    }

    public function testDryRunRealRunAndIdempotence(): void
    {
        // T53.
        $this->fixtures(1000);

        $this->cli()->backfill([], ['dry-run' => true]);
        self::assertSame([], $this->rows());
        self::assertContains('Posts scanned:            1000', WpStubs::$cliOutput);
        self::assertContains('Rows that would be written: 2000', WpStubs::$cliOutput);

        WpStubs::$cliOutput = [];
        $this->cli()->backfill([], []);
        self::assertCount(2000, $this->rows());
        self::assertContains('Rows written:               2000', WpStubs::$cliOutput);
        self::assertContains('Success: Backfill complete.', WpStubs::$cliOutput);

        WpStubs::$cliOutput = [];
        $this->cli()->backfill([], []);
        self::assertCount(2000, $this->rows());
        self::assertContains('Rows written:               0', WpStubs::$cliOutput);
        self::assertContains('Rows already present:     2000', WpStubs::$cliOutput);

        WpStubs::$cliOutput = [];
        $this->cli()->backfill([], ['dry-run' => true]);
        self::assertContains('Rows already present:     2000', WpStubs::$cliOutput);
    }

    public function testResumeAndKeysetPaging(): void
    {
        // T54.
        $this->fixtures(30);
        $result = $this->plugin->backfill()->run(new BackfillRequest(20, 7));
        self::assertSame(10, $result->scanned);
        self::assertSame(30, $result->lastId);
        self::assertCount(20, $this->rows());

        $posts = array_filter($this->wpdb()->log, static fn (string $q): bool => str_contains($q, 'wp_posts'));
        self::assertNotEmpty($posts);
        foreach ($posts as $query) {
            self::assertStringNotContainsStringIgnoringCase('offset', $query);
            self::assertStringContainsString('ID > ', $query);
        }
        self::assertStringContainsString('ORDER BY ID ASC LIMIT 7', array_values($posts)[0]);
    }

    public function testFromIdFlag(): void
    {
        $this->fixtures(10);
        $this->cli()->backfill([], ['from-id' => '5', 'batch' => '3']);
        self::assertCount(10, $this->rows());
        self::assertContains('Posts scanned:            5', WpStubs::$cliOutput);
    }

    public function testAdminAjaxChecksNonceAndCapabilityAndResumes(): void
    {
        // T55.
        $this->fixtures(12);
        $page = new AdminPage($this->plugin, static function (): void {
        });

        $_POST = $_REQUEST = [];
        try {
            $page->handleBackfill();
            self::fail('missing nonce accepted');
        } catch (WpJsonResponse $response) {
            self::assertFalse($response->success);
            self::assertSame(403, $response->status);
        }

        $_POST = $_REQUEST = ['nonce' => wp_create_nonce(AdminPage::ACTION_BACKFILL)];
        WpStubs::$caps = [];
        try {
            $page->handleBackfill();
            self::fail('user without manage_options accepted');
        } catch (WpJsonResponse $response) {
            self::assertFalse($response->success);
            self::assertSame(403, $response->status);
        }
        self::assertSame([], $this->rows());

        WpStubs::$caps = ['manage_options' => true];
        $responses = [];
        for ($i = 0; $i < 30; $i++) {
            try {
                $page->handleBackfill();
            } catch (WpJsonResponse $response) {
                self::assertTrue($response->success);
                $responses[] = $response->data;
                if ($response->data['done']) {
                    break;
                }
            }
        }
        $last = end($responses);
        self::assertSame(12, $last['scanned']);
        self::assertSame(24, $last['written']);
        self::assertSame(12, get_option(Backfill::CURSOR_OPTION)['last_id']);
    }

    public function testAdminBatchResumesFromStoredCursor(): void
    {
        $this->fixtures(5);
        update_option(Backfill::CURSOR_OPTION, ['last_id' => 3, 'scanned' => 3, 'done' => false]);
        $_POST = $_REQUEST = ['nonce' => wp_create_nonce(AdminPage::ACTION_BACKFILL)];
        try {
            (new AdminPage($this->plugin, static function (): void {
            }))->handleBackfill();
        } catch (WpJsonResponse $response) {
            self::assertSame(5, $response->data['scanned']);
            self::assertSame(4, $response->data['written']);
        }
        self::assertCount(4, $this->rows());
    }

    public function testExpiredPostsAndPostDates(): void
    {
        // T56.
        $old = Table::toDate($this->clock->now() - 200 * 86400);
        $recent = Table::toDate($this->clock->now() - 10 * 86400);
        WpStubs::addPostQuietly(['post_date_gmt' => $old], [self::META_KEY => 'https://example.com/old']);
        WpStubs::addPostQuietly(['post_date_gmt' => $recent], [self::META_KEY => 'https://example.com/recent']);

        $result = $this->plugin->backfill()->run(new BackfillRequest());
        self::assertSame(1, $result->expired);
        self::assertCount(1, $this->rows());
        $row = array_values($this->rows())[0];
        self::assertSame($recent, $row['first_seen']);
        self::assertSame($recent, $row['last_seen']);

        $this->plugin->backfill()->run(new BackfillRequest(includeExpired: true));
        self::assertCount(2, $this->rows());
    }

    public function testTrashedIncludedAutoDraftExcluded(): void
    {
        // T57.
        WpStubs::addPostQuietly(['post_status' => 'trash'], [self::META_KEY => 'https://example.com/trashed']);
        WpStubs::addPostQuietly(['post_status' => 'auto-draft'], [self::META_KEY => 'https://example.com/auto']);
        $this->plugin->backfill()->run(new BackfillRequest());
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/trashed'));
        self::assertFalse(echo_skip_seen_is_seen('https://example.com/auto'));
    }

    public function testRejectedUrlsAreCounted(): void
    {
        WpStubs::addPostQuietly([], [self::META_KEY => 'https://www.mysite.test/self']);
        WpStubs::addPostQuietly([], [self::META_KEY => 'not a url']);
        $result = $this->plugin->backfill()->run(new BackfillRequest());
        self::assertSame(2, $result->scanned);
        self::assertSame(1, $result->withUrl);
        self::assertSame(1, $result->rejected);
    }

    public function testMetaKeyFlagSavesKeys(): void
    {
        $this->plugin->settings()->save(['meta_keys' => []]);
        WpStubs::addPostQuietly([], ['origin_link' => 'https://example.com/o']);
        try {
            $this->cli()->backfill([], []);
            self::fail('backfill without meta keys should stop');
        } catch (WpCliError) {
            self::assertSame([], $this->rows());
        }
        $this->cli()->backfill([], ['meta-key' => 'origin_link']);
        self::assertSame(['origin_link'], $this->plugin->settings()->metaKeys());
        self::assertCount(1, $this->rows());
    }

    public function testPostTypeAndSinceFlags(): void
    {
        WpStubs::addPostQuietly(['post_type' => 'news', 'post_date_gmt' => Table::toDate($this->clock->now() - 5 * 86400)], [self::META_KEY => 'https://example.com/n1']);
        WpStubs::addPostQuietly(['post_type' => 'news', 'post_date_gmt' => Table::toDate($this->clock->now() - 1 * 86400)], [self::META_KEY => 'https://example.com/n2']);
        $since = gmdate('Y-m-d', $this->clock->now() - 2 * 86400);
        $this->cli()->backfill([], ['post-type' => 'news', 'since' => $since]);
        self::assertCount(1, $this->rows());
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/n2'));
    }

    public function testExitsNonZeroWhenTableCannotBeCreated(): void
    {
        update_option('echo_skip_seen_db_version', 0);
        $this->wpdb()->tables = [];
        $this->wpdb()->failPattern = '/^CREATE TABLE/';
        $this->expectException(WpCliError::class);
        $this->cli()->backfill([], []);
    }

    public function testAdminSaveRequiresNonceAndCapability(): void
    {
        $page = new AdminPage($this->plugin, static function (): void {
        });
        $_POST = $_REQUEST = ['meta_keys' => "a_key\n<bad>\nb_key", 'retention_days' => '30', 'enabled' => '1'];
        try {
            $page->handleSave();
            self::fail('missing nonce accepted');
        } catch (WpDieException) {
            self::assertSame([self::META_KEY], $this->plugin->settings()->metaKeys());
        }

        $_POST['_wpnonce'] = $_REQUEST['_wpnonce'] = wp_create_nonce(AdminPage::ACTION_SAVE);
        WpStubs::$caps = [];
        try {
            $page->handleSave();
            self::fail('user without manage_options accepted');
        } catch (WpDieException) {
            self::assertSame([self::META_KEY], $this->plugin->settings()->metaKeys());
        }

        WpStubs::$caps = ['manage_options' => true];
        $page->handleSave();
        self::assertSame(['a_key', 'b_key'], $this->plugin->settings()->metaKeys());
        self::assertSame(30, $this->plugin->settings()->retentionDays());
        self::assertFalse($this->plugin->settings()->debug());
        self::assertStringContainsString('echo-skip-seen=saved', (string) WpStubs::$redirect);
    }
}
