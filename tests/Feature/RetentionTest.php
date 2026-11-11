<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Plugin;
use IndianOpinion\EchoSkipSeen\Retention;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WpStubs;

/**
 * PRD 4.9, acceptance tests T58 to T60.
 */
final class RetentionTest extends PluginTestCase
{
    private function addRow(string $hash, int $firstAgo, int $lastAgo): void
    {
        $now = $this->clock->now();
        $this->wpdb()->tables['wp_echo_skip_seen']['rows'][$hash] = [
            'key_kind' => KeySet::KIND_CANONICAL,
            'key_version' => 1,
            'post_id' => 1,
            'first_seen' => Table::toDate($now - $firstAgo * 86400),
            'last_seen' => Table::toDate($now - $lastAgo * 86400),
        ];
    }

    public function testPruneInBatches(): void
    {
        // T58.
        $this->plugin->settings()->save(['retention_days' => 30]);
        for ($i = 0; $i < 12; $i++) {
            $this->addRow(md5('old' . $i), 31, 31);
        }
        $this->addRow(md5('fresh'), 5, 5);

        $deleted = $this->plugin->retention()->prune(5);
        self::assertSame(12, $deleted);
        self::assertCount(1, $this->rows());
        $deletes = array_filter($this->wpdb()->log, static fn (string $q): bool => str_starts_with($q, 'DELETE'));
        self::assertCount(3, $deletes);
        self::assertStringContainsString('LIMIT 5', array_values($deletes)[0]);
    }

    public function testRetentionZeroKeepsEverything(): void
    {
        // T58.
        $this->plugin->settings()->save(['retention_days' => 0]);
        $this->addRow(md5('ancient'), 4000, 4000);
        self::assertSame(0, $this->plugin->retention()->prune());
        self::assertCount(1, $this->rows());
        self::assertTrue($this->plugin->lookup()->isSeen('https://example.com/never') === false);
    }

    public function testRefreshedRowSurvives(): void
    {
        // T59.
        $this->plugin->settings()->save(['retention_days' => 30]);
        $this->addRow(md5('refreshed'), 60, 10);
        $this->plugin->retention()->prune();
        self::assertCount(1, $this->rows());
    }

    public function testRetentionFilter(): void
    {
        add_filter('echo_skip_seen_retention_days', static fn (): int => 1);
        $this->addRow(md5('two-days'), 2, 2);
        self::assertSame(1, $this->plugin->retention()->prune());
    }

    public function testScheduleOnActivationAndDeactivation(): void
    {
        // T60.
        self::assertNotFalse(wp_next_scheduled(Retention::HOOK));
        $this->plugin->deactivate(false);
        self::assertFalse(wp_next_scheduled(Retention::HOOK));
        $this->plugin->activate(false);
        self::assertNotFalse(wp_next_scheduled(Retention::HOOK));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMustUseSchedulesOnInitOnce(): void
    {
        // T60.
        WpStubs::reset();
        define('WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins');
        $plugin = new Plugin('/srv/www/wp-content/mu-plugins/echo-skip-seen/echo-skip-seen.php', $this->clock);
        $plugin->register();
        Plugin::setInstance($plugin);
        self::assertTrue($plugin->isMustUse());
        self::assertSame(0, stub_callback_count('activate_echo-skip-seen/echo-skip-seen.php'));

        self::assertFalse(wp_next_scheduled(Retention::HOOK));
        do_action('init');
        $first = wp_next_scheduled(Retention::HOOK);
        self::assertNotFalse($first);
        WpStubs::$cron[1][Retention::HOOK] = 12345;
        do_action('init');
        self::assertSame(12345, wp_next_scheduled(Retention::HOOK));
        self::assertCount(1, WpStubs::$cron[1]);
    }

    public function testCronHookRunsPrune(): void
    {
        $this->plugin->settings()->save(['retention_days' => 1]);
        $this->addRow(md5('x'), 3, 3);
        do_action(Retention::HOOK);
        self::assertCount(0, $this->rows());
    }
}
