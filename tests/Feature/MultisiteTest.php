<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Feature;

use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Plugin;
use IndianOpinion\EchoSkipSeen\Tests\Support\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Site;
use WpStubs;

/**
 * PRD 4.10, acceptance tests T61 and T62.
 */
final class MultisiteTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::reset();
        WpStubs::$multisite = true;
        WpStubs::$sites = [1, 2];
        $this->plugin = $this->newRequest();
    }

    private function onSite(int $site, callable $callback): mixed
    {
        switch_to_blog($site);
        try {
            // A real request on another site builds services for that site.
            $plugin = $this->newRequest();

            return $callback($plugin);
        } finally {
            restore_current_blog();
        }
    }

    public function testSitesAreIsolated(): void
    {
        // T61.
        $this->plugin->activate(true);
        $this->onSite(1, static function (Plugin $plugin): void {
            $plugin->settings()->save(['meta_keys' => ['site_one_key'], 'retention_days' => 10]);
            echo_skip_seen_record('https://example.com/only-on-one', 1);
            $plugin->counters()->increment('checked', 3);
            $plugin->counters()->flush();
        });

        $this->onSite(2, function (Plugin $plugin): void {
            self::assertSame('https://example.com/only-on-one', $this->dup('https://example.com/only-on-one'));
            self::assertSame([], $plugin->settings()->metaKeys());
            self::assertSame(90, $plugin->settings()->retentionDays());
            self::assertSame(1, $plugin->counters()->snapshot()['checked']);
        });

        $this->onSite(1, function (Plugin $plugin): void {
            self::assertTrue($this->dup('https://example.com/only-on-one'));
            self::assertSame(['site_one_key'], $plugin->settings()->metaKeys());
        });
        self::assertArrayHasKey('wp_echo_skip_seen', $this->wpdb()->tables);
        self::assertArrayHasKey('wp_2_echo_skip_seen', $this->wpdb()->tables);
    }

    public function testNetworkActivationNewSiteAndDeletedSite(): void
    {
        // T62.
        WpStubs::$sites = range(1, 205);
        $this->plugin->activate(true);
        self::assertCount(205, $this->wpdb()->tables);

        WpStubs::$networkActive['echo-skip-seen/echo-skip-seen.php'] = true;
        do_action('wp_initialize_site', new WP_Site(300));
        self::assertArrayHasKey('wp_300_echo_skip_seen', $this->wpdb()->tables);

        $tables = apply_filters('wpmu_drop_tables', [], 300);
        self::assertContains('wp_300_echo_skip_seen', $tables);
    }

    public function testSwitchingSitesInOneRequestKeepsCachesApart(): void
    {
        // T61 within one request: an importer that switches sites.
        $this->plugin->activate(true);
        echo_skip_seen_record('https://example.com/shared', 1);
        self::assertTrue($this->dup('https://example.com/shared'));
        switch_to_blog(2);
        self::assertSame('https://example.com/shared', $this->dup('https://example.com/shared'));
        restore_current_blog();
        self::assertTrue($this->dup('https://example.com/shared'));
    }

    public function testCountersFromASwitchedSiteLandOnThatSite(): void
    {
        // T61 within one request: counts follow the site they happened on.
        $this->plugin->activate(true);
        switch_to_blog(2);
        echo_skip_seen_record('https://example.com/two', 5);
        self::assertTrue($this->dup('https://example.com/two'));
        restore_current_blog();
        self::assertSame('https://example.com/one', $this->dup('https://example.com/one'));

        $this->plugin->counters()->flush();

        $counts = get_option(\IndianOpinion\EchoSkipSeen\Counters::OPTION);
        self::assertIsArray($counts);
        self::assertSame(1, $counts['counts']['checked']);
        self::assertSame(0, $counts['counts']['skipped']);
        self::assertSame(0, $counts['counts']['recorded']);

        switch_to_blog(2);
        $counts = get_option(\IndianOpinion\EchoSkipSeen\Counters::OPTION);
        restore_current_blog();
        self::assertIsArray($counts);
        self::assertSame(1, $counts['counts']['checked']);
        self::assertSame(1, $counts['counts']['skipped']);
        self::assertSame(1, $counts['counts']['recorded']);
    }

    public function testNewSiteIgnoredWhenNotNetworkActive(): void
    {
        do_action('wp_initialize_site', new WP_Site(301));
        self::assertArrayNotHasKey('wp_301_echo_skip_seen', $this->wpdb()->tables);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMustUseCreatesSiteTableOnFirstUse(): void
    {
        // T62: lazily, on init, on any request type.
        define('WPMU_PLUGIN_DIR', '/srv/www/wp-content/mu-plugins');
        $this->onSite(2, function (): void {
            $plugin = new Plugin('/srv/www/wp-content/mu-plugins/echo-skip-seen/echo-skip-seen.php', $this->clock);
            $plugin->register();
            self::assertFalse($plugin->schema()->isReady());
            do_action('init');
            self::assertTrue($plugin->schema()->isReady());
            self::assertSame(Schema::VERSION, get_option(Schema::VERSION_OPTION));
        });
        self::assertArrayHasKey('wp_2_echo_skip_seen', $this->wpdb()->tables);
        self::assertArrayNotHasKey('wp_echo_skip_seen', $this->wpdb()->tables);
    }
}
