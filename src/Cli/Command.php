<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Cli;

use IndianOpinion\EchoSkipSeen\Admin\UrlReport;
use IndianOpinion\EchoSkipSeen\BackfillRequest;
use IndianOpinion\EchoSkipSeen\BackfillResult;
use IndianOpinion\EchoSkipSeen\Plugin;
use IndianOpinion\EchoSkipSeen\Settings;
use WP_CLI;

/**
 * Skips feed items whose article this site has already imported.
 */
final class Command
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * Seeds the table from existing posts.
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Posts per batch.
     * ---
     * default: 500
     * ---
     *
     * [--from-id=<id>]
     * : Start after this post id (to resume).
     * ---
     * default: 0
     * ---
     *
     * [--since=<date>]
     * : Only posts dated on or after this date (GMT), for example 2026-01-31.
     *
     * [--post-type=<types>]
     * : Comma-separated post types. Defaults to the configured list.
     *
     * [--meta-key=<keys>]
     * : Comma-separated source URL meta keys, in order. Saved to the settings unless --dry-run.
     *
     * [--include-expired]
     * : Also write posts older than the retention period.
     *
     * [--dry-run]
     * : Count and report; write nothing.
     *
     * ## EXAMPLES
     *
     *     wp skip-seen backfill --meta-key=source_url --dry-run
     *     wp skip-seen backfill --batch=1000 --from-id=120000
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function backfill(array $args, array $assoc): void
    {
        $dryRun = (bool) ($assoc['dry-run'] ?? false);

        $metaKeys = null;
        if (isset($assoc['meta-key'])) {
            $metaKeys = Settings::sanitiseMetaKeys(str_replace(',', "\n", (string) $assoc['meta-key']));
            if ($metaKeys === []) {
                WP_CLI::error('--meta-key holds no valid meta key name.');

                return;
            }
            if (!$dryRun) {
                $this->plugin->settings()->save(['meta_keys' => $metaKeys]);
                WP_CLI::log('Saved source URL meta keys: ' . implode(', ', $metaKeys));
            }
        } elseif ($this->plugin->settings()->metaKeys() === []) {
            WP_CLI::error('No source URL meta key is configured. Pass --meta-key=<key> or set it under Tools, Echo Skip Seen.');

            return;
        }

        $since = null;
        if (isset($assoc['since'])) {
            $since = strtotime((string) $assoc['since'] . ' UTC');
            if ($since === false) {
                WP_CLI::error('--since is not a date.');

                return;
            }
        }

        $postTypes = [];
        if (isset($assoc['post-type'])) {
            foreach (explode(',', (string) $assoc['post-type']) as $type) {
                $type = sanitize_key($type);
                if ($type !== '') {
                    $postTypes[] = $type;
                }
            }
        }

        if (!$this->ensureSchema()) {
            return;
        }

        $request = new BackfillRequest(
            max(0, (int) ($assoc['from-id'] ?? 0)),
            (int) ($assoc['batch'] ?? BackfillRequest::DEFAULT_BATCH),
            $postTypes,
            $since,
            (bool) ($assoc['include-expired'] ?? false),
            $dryRun,
            $metaKeys
        );

        $backfill = $this->plugin->backfill();
        try {
            $count = $backfill->countPosts($request);
            $bar = \WP_CLI\Utils\make_progress_bar('Backfilling', max(1, $count));
            $tick = [$bar, 'tick'];
            $finish = [$bar, 'finish'];
            $total = $backfill->run($request, static function (BackfillResult $batch) use ($tick): void {
                if (is_callable($tick)) {
                    $tick(max(1, $batch->scanned));
                }
                // Each batch primes the post and meta caches; on a site with
                // millions of posts they would grow until PHP runs out of
                // memory. WP-CLI's helper empties the in-process object cache
                // (and the saved query log when SAVEQUERIES is on).
                if (function_exists('WP_CLI\Utils\wp_clear_object_cache')) {
                    \WP_CLI\Utils\wp_clear_object_cache();
                }
            });
            if (is_callable($finish)) {
                $finish();
            }
        } catch (\Throwable $e) {
            WP_CLI::error($e->getMessage());

            return;
        }
        $this->plugin->counters()->flush();

        $this->printTotals($total, $dryRun);
        WP_CLI::success($dryRun ? 'Dry run complete. Nothing was written.' : 'Backfill complete.');
    }

    /**
     * Shows both keys of a URL, both hashes, and whether each is in the table.
     *
     * ## OPTIONS
     *
     * <url>
     * : The item URL.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function check(array $args, array $assoc): void
    {
        $url = $args[0] ?? '';
        $report = (new UrlReport($this->plugin))->build($url);
        if ($report['keys'] === null) {
            WP_CLI::log($report['note']);

            return;
        }

        $items = [
            ['field' => 'canonical key', 'value' => $report['keys']['canonical']],
            ['field' => 'canonical hash', 'value' => $report['keys']['canonical_hash']],
            ['field' => 'canonical row', 'value' => $report['canonical_present']],
            ['field' => 'plain key', 'value' => $report['keys']['plain']],
            ['field' => 'plain hash', 'value' => $report['keys']['plain_hash']],
            ['field' => 'plain row', 'value' => $report['plain_present']],
            ['field' => 'key version', 'value' => (string) $report['keys']['version']],
            ['field' => 'lookup would skip', 'value' => $report['seen'] ? 'yes' : 'no'],
        ];
        \WP_CLI\Utils\format_items((string) ($assoc['format'] ?? 'table'), $items, ['field', 'value']);
    }

    /**
     * Records a URL as imported.
     *
     * ## OPTIONS
     *
     * <url>
     * : The source URL.
     *
     * [--post-id=<id>]
     * : The post imported from it.
     * ---
     * default: 0
     * ---
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function record(array $args, array $assoc): void
    {
        $url = $args[0] ?? '';
        $rows = $this->plugin->recorder()->record($url, max(0, (int) ($assoc['post-id'] ?? 0)));
        $this->plugin->counters()->flush();
        WP_CLI::success(sprintf('%d new row(s) written.', $rows));
    }

    /**
     * Prints the counters, or resets them.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * [--reset]
     * : Set every counter to zero.
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function stats(array $args, array $assoc): void
    {
        if ((bool) ($assoc['reset'] ?? false)) {
            $this->plugin->counters()->reset();
            WP_CLI::success('Counters reset.');

            return;
        }

        $counters = apply_filters('echo_skip_seen_counters', []);
        $counters = is_array($counters) ? $counters : [];
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line((string) wp_json_encode($counters));

            return;
        }

        $items = [];
        foreach (['checked', 'skipped', 'recorded', 'failed_open'] as $name) {
            $since = (int) ($counters['since'][$name] ?? 0);
            $items[] = [
                'counter' => $name,
                'value' => (string) ($counters[$name] ?? 0),
                'since' => $since > 0 ? gmdate('Y-m-d H:i:s', $since) . ' UTC' : '-',
            ];
        }
        $rate = $counters['skip_rate'] ?? null;
        $items[] = ['counter' => 'skip_rate', 'value' => is_numeric($rate) ? round((float) $rate * 100, 1) . '%' : '-', 'since' => '-'];
        \WP_CLI\Utils\format_items('table', $items, ['counter', 'value', 'since']);
    }

    /**
     * Deletes rows older than the retention period now.
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function prune(array $args, array $assoc): void
    {
        $deleted = $this->plugin->retention()->prune();
        $this->plugin->counters()->flush();
        WP_CLI::success(sprintf('%d expired row(s) deleted.', $deleted));
    }

    /**
     * Creates or repairs the table and checks its columns and indexes.
     *
     * Exits non-zero when the schema is still incomplete.
     *
     * @subcommand verify-schema
     *
     * @param list<string>          $args
     * @param array<string, string|bool> $assoc
     */
    public function verifySchema(array $args, array $assoc): void
    {
        $missing = $this->plugin->schema()->install();
        if ($missing !== []) {
            WP_CLI::error('Schema check failed. Missing: ' . implode(', ', $missing));

            return;
        }

        WP_CLI::success('Schema ok: ' . $this->plugin->table()->name());
    }

    private function ensureSchema(): bool
    {
        if ($this->plugin->schema()->isReady()) {
            return true;
        }

        $missing = $this->plugin->schema()->install();
        if ($missing !== []) {
            WP_CLI::error('The table cannot be created or verified. Missing: ' . implode(', ', $missing));

            return false;
        }

        return true;
    }

    private function printTotals(BackfillResult $total, bool $dryRun): void
    {
        WP_CLI::log(sprintf('Posts scanned:            %d', $total->scanned));
        WP_CLI::log(sprintf('Posts with a source URL:  %d', $total->withUrl));
        WP_CLI::log(sprintf('Rows %s %d', $dryRun ? 'that would be written:' : 'written:              ', $total->written));
        WP_CLI::log(sprintf('Rows already present:     %d', $total->present));
        WP_CLI::log(sprintf('URLs rejected:            %d', $total->rejected));
        WP_CLI::log(sprintf('Older than retention:     %d', $total->expired));
        WP_CLI::log(sprintf('Last post id:             %d', $total->lastId));
    }
}
