<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use WpStubs;

/**
 * PRD 4.5, acceptance tests T31 to T42.
 */
final class RecordPathTest extends PluginTestCase
{
    public function testDraftWithMetaIsRecorded(): void
    {
        // T31.
        WpStubs::insertPost(['post_status' => 'draft'], [self::META_KEY => 'https://m.example.com/news/story-1/']);
        self::assertCount(2, $this->rows());
        $kinds = array_column($this->rows(), 'key_kind');
        sort($kinds);
        self::assertSame([KeySet::KIND_CANONICAL, KeySet::KIND_PLAIN], $kinds);
    }

    public function testAutoDraftPageAndFilterAreNotRecorded(): void
    {
        // T31.
        WpStubs::insertPost(['post_status' => 'auto-draft'], [self::META_KEY => 'https://example.com/one']);
        WpStubs::insertPost(['post_type' => 'page'], [self::META_KEY => 'https://example.com/two']);
        add_filter('echo_skip_seen_should_record', static fn (): bool => false, 10, 3);
        WpStubs::insertPost([], [self::META_KEY => 'https://example.com/three']);
        self::assertCount(0, $this->rows());
    }

    public function testCustomPostTypeList(): void
    {
        add_filter('echo_skip_seen_post_types', static fn (): array => ['post', 'news_item']);
        WpStubs::insertPost(['post_type' => 'news_item'], [self::META_KEY => 'https://example.com/n']);
        self::assertCount(1, $this->rows());
    }

    public function testFirstValidMetaKeyWins(): void
    {
        // T32.
        $this->plugin->settings()->save(['meta_keys' => ['first_key', 'second_key']]);
        $post = WpStubs::insertPost([], ['first_key' => 'not a url', 'second_key' => 'https://example.com/second']);
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/second'));

        WpStubs::insertPost([], ['first_key' => 'https://example.com/first', 'second_key' => 'https://example.com/other']);
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/first'));
        self::assertFalse(echo_skip_seen_is_seen('https://example.com/other'));
        self::assertGreaterThan(0, $post->ID);
    }

    public function testMetaAfterInsertIsRecordedOnce(): void
    {
        // T33.
        $post = WpStubs::insertPost([], []);
        self::assertCount(0, $this->rows());
        add_post_meta($post->ID, self::META_KEY, 'https://m.example.com/late/');
        self::assertCount(2, $this->rows());
        self::assertSame(2, $this->counter('recorded'));
    }

    public function testInsertAndMetaInEitherOrderWriteTwoRows(): void
    {
        // T33.
        WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/before/'], true);
        self::assertCount(2, $this->rows());
        WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/after/'], false);
        self::assertCount(4, $this->rows());
        $statements = array_filter($this->wpdb()->log, static fn (string $q): bool => str_starts_with($q, 'INSERT'));
        self::assertCount(2, $statements);
    }

    public function testRecordFunctionAndAction(): void
    {
        // T34.
        self::assertTrue(echo_skip_seen_record('https://m.example.com/f/', 0));
        self::assertTrue(echo_skip_seen_record('https://m.example.com/g/', 12));
        do_action('echo_skip_seen_record_url', 'https://m.example.com/h/', 0);
        do_action('echo_skip_seen_record_url', 'https://m.example.com/i/', 14);
        self::assertCount(8, $this->rows());
        self::assertContains(12, array_column($this->rows(), 'post_id'));
        self::assertContains(14, array_column($this->rows(), 'post_id'));
        self::assertFalse(echo_skip_seen_record('https://m.example.com/f/', 0), 'second record writes no new row');
    }

    public function testOwnHostIsNeverRecordedNorLookedUp(): void
    {
        // T35.
        WpStubs::insertPost([], [self::META_KEY => 'https://mysite.test/?p=12']);
        self::assertFalse(echo_skip_seen_record('https://m.mysite.test/2026/story'));
        self::assertCount(0, $this->rows());
        $before = $this->tableQueryCount();
        self::assertSame('https://www.mysite.test/a', $this->dup('https://www.mysite.test/a'));
        self::assertSame($before, $this->tableQueryCount());
    }

    public function testIdenticalKeysWriteOneRow(): void
    {
        // T36.
        echo_skip_seen_record('https://example.com/a');
        self::assertCount(1, $this->rows());
    }

    public function testSameUrlFromTwoPostsKeepsFirstPostId(): void
    {
        // T37.
        WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/twice/']);
        $first = array_values($this->rows())[0]['post_id'];
        $this->clock->advance(7200);
        WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/twice/']);
        self::assertCount(2, $this->rows());
        foreach ($this->rows() as $row) {
            self::assertSame($first, $row['post_id']);
            self::assertSame(gmdate('Y-m-d H:i:s', $this->clock->now()), $row['last_seen']);
        }
        self::assertSame(2, $this->counter('recorded'));
    }

    public function testMissingTableNeverInterruptsTheInsert(): void
    {
        // T38.
        $this->wpdb()->tables = [];
        $post = WpStubs::insertPost([], [self::META_KEY => 'https://example.com/lost']);
        self::assertNotNull(get_post($post->ID));
        self::assertSame(1, $this->counter('failed_open'));
        self::assertSame([], $this->phpErrors);
    }

    public function testRevisionsAndAutosavesAreIgnored(): void
    {
        // T39.
        WpStubs::insertPost(['post_type' => 'revision', 'post_parent' => 1], [self::META_KEY => 'https://example.com/r']);
        WpStubs::insertPost(['post_type' => 'revision', 'post_parent' => 1, 'post_name' => '1-autosave-v1'], [self::META_KEY => 'https://example.com/s']);
        add_filter('echo_skip_seen_post_types', static fn (): array => ['post', 'revision']);
        WpStubs::insertPost(['post_type' => 'revision', 'post_parent' => 1], [self::META_KEY => 'https://example.com/t']);
        self::assertCount(0, $this->rows());
    }

    public function testDeletingKeepsRowsUnlessFiltered(): void
    {
        // T40.
        $a = WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/a/']);
        wp_trash_post($a->ID);
        wp_delete_post($a->ID);
        self::assertCount(2, $this->rows());

        add_filter('echo_skip_seen_delete_on_post_delete', static fn (): bool => true);
        $b = WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/b/']);
        self::assertCount(4, $this->rows());
        wp_delete_post($b->ID);
        self::assertCount(2, $this->rows());
    }

    public function testChangedSourceUrlAddsRowsAndKeepsOld(): void
    {
        // T41.
        $post = WpStubs::insertPost([], [self::META_KEY => 'https://m.example.com/old/']);
        update_post_meta($post->ID, self::META_KEY, 'https://m.example.com/new/');
        self::assertCount(4, $this->rows());
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/old'));
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/new'));
    }

    public function testLookupMissWritesNothing(): void
    {
        // T42.
        $this->dup('https://example.com/unknown');
        self::assertSame([], $this->rows());
        $writes = array_filter($this->wpdb()->log, static fn (string $q): bool => !str_starts_with($q, 'SELECT') && !str_starts_with($q, 'SHOW'));
        $before = count($writes);
        $this->dup('https://example.com/unknown-2');
        $after = count(array_filter($this->wpdb()->log, static fn (string $q): bool => !str_starts_with($q, 'SELECT') && !str_starts_with($q, 'SHOW')));
        self::assertSame($before, $after);
    }

    public function testEmptyMetaKeyListRecordsNothingAutomatically(): void
    {
        $this->plugin->settings()->save(['meta_keys' => []]);
        WpStubs::insertPost([], [self::META_KEY => 'https://example.com/a']);
        self::assertCount(0, $this->rows());
    }

    public function testMetaKeyFilter(): void
    {
        $this->plugin->settings()->save(['meta_keys' => []]);
        add_filter('echo_skip_seen_source_meta_keys', static fn (): array => ['from_filter']);
        WpStubs::insertPost([], ['from_filter' => 'https://example.com/f']);
        self::assertCount(1, $this->rows());
    }

    public function testRecordedActionFires(): void
    {
        $seen = [];
        add_action('echo_skip_seen_recorded', static function (string $url, int $postId, int $rows) use (&$seen): void {
            $seen[] = [$url, $postId, $rows];
        }, 10, 3);
        echo_skip_seen_record('https://m.example.com/x/', 5);
        self::assertSame([['https://m.example.com/x/', 5, 2]], $seen);
    }

    public function testNonUrlsAreNotRecorded(): void
    {
        // T2 on the record path.
        foreach (['ftp://example.com/a', 'example.com/a', 'javascript:alert(1)', 'A headline', 'https://'] as $value) {
            self::assertFalse(echo_skip_seen_record($value));
        }
        self::assertSame([], $this->rows());
        self::assertSame(0, $this->counter('failed_open'));
    }
}
