<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Support;

use IndianOpinion\EchoSkipSeen\Keys\Canonicalizer;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\KeySet;
use IndianOpinion\EchoSkipSeen\Keys\Rules;
use IndianOpinion\EchoSkipSeen\Plugin;
use PHPUnit\Framework\TestCase;
use WpStubs;

/**
 * Boots the plugin on the stub layer with a fixed clock, an installed
 * table and "source_url" as the configured source meta key.
 */
abstract class PluginTestCase extends TestCase
{
    public const META_KEY = 'source_url';

    protected FixedClock $clock;

    protected Plugin $plugin;

    /** @var list<string> PHP notices and warnings raised during the test. */
    protected array $phpErrors = [];

    protected function setUp(): void
    {
        parent::setUp();
        WpStubs::reset();
        $this->clock = new FixedClock();
        $this->phpErrors = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->phpErrors[] = $message;

            return true;
        });
        $this->plugin = $this->bootPlugin();
        $this->plugin->activate(false);
        $this->plugin->settings()->save(['meta_keys' => [self::META_KEY]]);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        Plugin::setInstance(null);
        parent::tearDown();
    }

    protected function bootPlugin(int $keyVersion = Canonicalizer::VERSION): Plugin
    {
        $plugin = new Plugin('/srv/www/wp-content/plugins/echo-skip-seen/echo-skip-seen.php', $this->clock, $keyVersion);
        $plugin->register();
        Plugin::setInstance($plugin);

        return $plugin;
    }

    /**
     * Rebuilds the plugin (a new request) on the same stub state.
     */
    protected function newRequest(int $keyVersion = Canonicalizer::VERSION): Plugin
    {
        $this->plugin->counters()->flush();
        // Drop the old instance's callbacks; keep everything a test added.
        foreach (WpStubs::$filters as $hook => $byPriority) {
            foreach ($byPriority as $priority => $callbacks) {
                WpStubs::$filters[$hook][$priority] = array_values(array_filter(
                    $callbacks,
                    static fn (array $entry): bool => !(is_array($entry[0])
                        && is_object($entry[0][0])
                        && str_starts_with(get_class($entry[0][0]), 'IndianOpinion\\EchoSkipSeen\\'))
                ));
            }
        }
        $this->plugin = $this->bootPlugin($keyVersion);

        return $this->plugin;
    }

    protected function keys(string $url): KeySet
    {
        return (new KeyFactory(static fn (): Rules => Rules::fromFilters()))->forUrl($url);
    }

    protected function key(string $url): string
    {
        return $this->keys($url)->canonical;
    }

    protected function wpdb(): \wpdb
    {
        global $wpdb;

        return $wpdb;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function rows(): array
    {
        return $this->wpdb()->rows();
    }

    protected function tableQueryCount(): int
    {
        return count($this->wpdb()->tableQueries());
    }

    protected function counter(string $name): int
    {
        return (int) $this->plugin->counters()->snapshot()[$name];
    }

    protected function dup(mixed $value): mixed
    {
        return apply_filters('echo_filter_dup_check', $value);
    }
}
