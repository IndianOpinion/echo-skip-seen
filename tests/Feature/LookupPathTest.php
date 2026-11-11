<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WpStubs;

/**
 * PRD 4.4 and 4.6, acceptance tests T29, T30 and T43 to T50.
 */
final class LookupPathTest extends PluginTestCase
{
    public function testPreProcessingFilterOnBothPaths(): void
    {
        // T29.
        add_filter('echo_skip_seen_url', static function (string $url, string $context): string {
            return $url === 'https://wrap.example.net/r/1' ? 'https://example.com/real-article' : $url;
        }, 10, 2);
        echo_skip_seen_record('https://example.com/real-article', 3);
        self::assertTrue($this->dup('https://wrap.example.net/r/1'));
    }

    public function testPreProcessingFilterReturningJunkIsIgnored(): void
    {
        // T29.
        echo_skip_seen_record('https://example.com/a', 3);
        add_filter('echo_skip_seen_url', static fn (): mixed => null, 10, 2);
        self::assertTrue($this->dup('https://example.com/a'));
        remove_all_filters('echo_skip_seen_url');
        add_filter('echo_skip_seen_url', static fn (): array => ['x'], 10, 2);
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/a'));
    }

    public function testGoogleNewsWrapperPassesUntouched(): void
    {
        // T30.
        $url = 'https://news.google.com/rss/articles/CBMiK2h0dHBzOi8vZXhhbXBsZS5jb20vYS?oc=5';
        $before = $this->tableQueryCount();
        self::assertSame($url, $this->dup($url));
        self::assertFalse(echo_skip_seen_record($url, 9));
        self::assertSame($before, $this->tableQueryCount());
        self::assertSame([], $this->rows());
        $counters = $this->plugin->counters()->snapshot();
        self::assertSame(0, $counters['checked'] + $counters['skipped'] + $counters['recorded'] + $counters['failed_open']);
        self::assertSame([], $this->phpErrors);
    }

    public function testCallbackRegisteredOnceAndNonStringsPass(): void
    {
        // T43.
        self::assertSame(1, stub_callback_count('echo_filter_dup_check'));
        $before = $this->tableQueryCount();
        self::assertTrue($this->dup(true));
        self::assertSame(7, $this->dup(7));
        self::assertSame(['a'], $this->dup(['a']));
        self::assertSame('', $this->dup(''));
        self::assertNull($this->dup(null));
        self::assertSame($before, $this->tableQueryCount());
    }

    public function testDisabledPassesThrough(): void
    {
        // T44: setting off.
        echo_skip_seen_record('https://example.com/a', 1);
        $this->plugin->settings()->save(['enabled' => false]);
        self::assertSame('https://example.com/a', $this->dup('https://example.com/a'));
        self::assertSame(0, $this->counter('checked'));

        // T44: filter off.
        $this->plugin->settings()->save(['enabled' => true]);
        add_filter('echo_skip_seen_enabled', static fn (): bool => false);
        self::assertSame('https://example.com/a', $this->dup('https://example.com/a'));
        self::assertFalse(echo_skip_seen_is_seen('https://example.com/a'));
        self::assertSame(0, $this->counter('checked'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisabledConstant(): void
    {
        // T44: constant.
        echo_skip_seen_record('https://example.com/a', 1);
        define('ECHO_SKIP_SEEN_DISABLED', true);
        self::assertSame('https://example.com/a', $this->dup('https://example.com/a'));
        self::assertSame(0, $this->counter('checked'));
    }

    public function testVariantMatchesAndRefreshesOncePerHour(): void
    {
        // T45.
        echo_skip_seen_record('https://m.example.com/a/amp?utm_source=x', 42);
        $skipped = [];
        add_action('echo_skip_seen_skipped', static function (string $url, int $postId, string $kind) use (&$skipped): void {
            $skipped[] = [$url, $postId, $kind];
        }, 10, 3);

        $this->clock->advance(2 * 3600);
        $request = $this->newRequest();
        self::assertTrue(apply_filters('echo_filter_dup_check', 'https://example.com/a'));
        self::assertSame(1, $this->counter('skipped'));
        self::assertSame([['https://example.com/a', 42, 'canonical']], $skipped);

        $updates = static fn (): int => count(array_filter($GLOBALS['wpdb']->log, static fn (string $q): bool => str_starts_with($q, 'UPDATE')));
        self::assertSame(1, $updates());
        $canonical = $this->keys('https://example.com/a')->canonicalHash;
        self::assertSame(Table::toDate($this->clock->now()), $this->rows()[$canonical]['last_seen']);

        // More hits in later requests within the hour write nothing.
        for ($i = 0; $i < 3; $i++) {
            $this->clock->advance(600);
            $this->newRequest();
            self::assertTrue($this->dup('https://www.example.com/a/'));
        }
        self::assertSame(1, $updates());

        // After an hour, one more refresh.
        $this->clock->advance(3600);
        $this->newRequest();
        self::assertTrue($this->dup('https://example.com/a'));
        self::assertSame(2, $updates());
        self::assertInstanceOf(\IndianOpinion\EchoSkipSeen\Plugin::class, $request);
    }

    public function testCrossKindMatch(): void
    {
        // T46: the item's plain hash finds a canonical row, and the other way round.
        $keys = $this->keys('https://example.com/item/');
        self::assertSame('example.com/item', $keys->canonical);
        self::assertSame('example.com/item', $keys->plain);
        $this->plugin->table()->insert($this->keys('https://example.com/item'), 1, $this->clock->now());
        self::assertCount(1, $this->rows());
        self::assertSame(KeySet::KIND_CANONICAL, array_values($this->rows())[0]['key_kind']);

        // An item whose plain key equals a stored canonical row.
        $item = $this->keys('https://www.example.com/item?utm_source=a');
        self::assertNotSame($item->canonicalHash, $item->plainHash);
        self::assertTrue($this->dup('https://example.com/item'));

        // A stored plain row matched by an item's canonical hash.
        $other = $this->keys('https://m.example.com/zz');
        $this->wpdb()->tables['wp_echo_skip_seen']['rows'] = [
            md5('example.com/zz') => ['key_kind' => KeySet::KIND_PLAIN, 'key_version' => 0, 'post_id' => 5, 'first_seen' => Table::toDate($this->clock->now()), 'last_seen' => Table::toDate($this->clock->now())],
        ];
        $this->newRequest();
        self::assertSame('example.com/zz', $other->canonical);
        self::assertTrue($this->dup('https://m.example.com/zz'));
    }

    public function testExpiredRowsDoNotMatchBeforePrune(): void
    {
        // T47.
        echo_skip_seen_record('https://example.com/old', 1);
        $this->clock->advance(91 * 86400);
        $this->newRequest();
        self::assertSame('https://example.com/old', $this->dup('https://example.com/old'));
        self::assertCount(1, $this->rows());
    }

    public function testMissReturnsExactInput(): void
    {
        // T48.
        $url = "  https://example.com/Unknown?utm_source=x&amp;id=1  ";
        self::assertSame($url, $this->dup($url));
    }

    public function testRequestCache(): void
    {
        // T49.
        $before = $this->tableQueryCount();
        self::assertSame('https://example.com/c', $this->dup('https://example.com/c'));
        self::assertSame('https://example.com/c', $this->dup('https://example.com/c'));
        self::assertSame($before + 1, $this->tableQueryCount());

        echo_skip_seen_record('https://example.com/c', 2);
        $afterRecord = $this->tableQueryCount();
        self::assertTrue($this->dup('https://example.com/c'));
        self::assertTrue($this->dup('https://m.example.com/c/'));
        self::assertSame($afterRecord, $this->tableQueryCount());
        self::assertSame(4, $this->counter('checked'));
    }

    public function testPublicFunctionAndFilter(): void
    {
        // T50.
        echo_skip_seen_record('https://example.com/p', 2);
        self::assertTrue(echo_skip_seen_is_seen('https://example.com/p'));
        self::assertFalse(echo_skip_seen_is_seen('https://example.com/q'));
        self::assertTrue(apply_filters('echo_skip_seen_is_seen', false, 'https://example.com/p'));
        self::assertFalse(apply_filters('echo_skip_seen_is_seen', false, 'https://example.com/q'));
        self::assertTrue(apply_filters('echo_skip_seen_is_seen', true, 'https://example.com/q'));
        self::assertFalse(echo_skip_seen_is_seen('not a url'));
    }

    public function testKeysFunction(): void
    {
        $keys = echo_skip_seen_keys('https://m.example.com/a/?utm_source=x');
        self::assertSame('example.com/a', $keys['canonical']);
        self::assertSame('m.example.com/a?utm_source=x', $keys['plain']);
        self::assertSame(md5('example.com/a'), $keys['canonical_hash']);
        self::assertSame([], echo_skip_seen_keys('headline'));
    }

    public function testCacheIsCapped(): void
    {
        $lookup = new \IndianOpinion\EchoSkipSeen\Lookup(
            $this->plugin->settings(),
            $this->plugin->gate(),
            $this->plugin->keys(),
            $this->plugin->table(),
            $this->plugin->schema(),
            $this->plugin->versions(),
            $this->plugin->counters(),
            $this->plugin->failOpen(),
            $this->plugin->log(),
            $this->clock,
            3
        );
        foreach (['a', 'b', 'c', 'd'] as $path) {
            $lookup->isSeen('https://example.com/' . $path);
        }
        $before = $this->tableQueryCount();
        $lookup->isSeen('https://example.com/d');
        self::assertSame($before, $this->tableQueryCount(), 'newest entry still cached');
        $lookup->isSeen('https://example.com/a');
        self::assertSame($before + 1, $this->tableQueryCount(), 'oldest entry dropped');
    }

    public function testPriorityConstantOrFilter(): void
    {
        WpStubs::$filters = [];
        add_filter('echo_skip_seen_priority', static fn (): int => 99);
        $this->newRequest();
        self::assertSame(99, has_filter('echo_filter_dup_check', [$this->plugin, 'filterDupCheck']));
    }

    public function testWholeFlowWithAFakeImporter(): void
    {
        // T72 on the stub layer; the real-WordPress run is a manual checklist in CONTRIBUTING.md.
        WpStubs::insertPost(['post_status' => 'publish'], [self::META_KEY => 'https://www.example.com/2026/11/story-title/']);
        self::assertTrue(apply_filters('echo_filter_dup_check', 'https://m.example.com/amp/2026/11/story-title?utm_source=feed'));

        remove_all_filters('echo_filter_dup_check');
        $url = 'https://m.example.com/amp/2026/11/story-title?utm_source=feed';
        self::assertSame($url, apply_filters('echo_filter_dup_check', $url));
    }

    public function testAnotherCallbacksTrueSurvives(): void
    {
        add_filter('echo_filter_dup_check', static fn (): bool => true, 5);
        self::assertTrue($this->dup('https://example.com/never-recorded'));
    }
}
