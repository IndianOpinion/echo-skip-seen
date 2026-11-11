<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Admin;

use IndianOpinion\EchoSkipSeen\Plugin;

/**
 * One Site Health test: table present, row count, and an informational
 * note when more than 95 percent of the last day's checks were skips.
 */
final class SiteHealth
{
    public const TEST = 'echo_skip_seen';

    /** Above this share of skips over 24 hours, the test adds a note. */
    public const HIGH_SKIP_RATIO = 0.95;

    /** Fewer checks than this in 24 hours is too small a sample for the note. */
    public const MIN_SAMPLE = 20;

    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_filter('site_status_tests', [$this, 'addTest']);
    }

    /**
     * @param mixed $tests
     * @return array<mixed>
     */
    public function addTest(mixed $tests): array
    {
        $tests = is_array($tests) ? $tests : [];
        $tests['direct'][self::TEST] = [
            'label' => __('Echo Skip Seen', 'echo-skip-seen'),
            'test' => [$this, 'run'],
        ];

        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    public function run(): array
    {
        $result = [
            'label' => __('The Echo Skip Seen table is present', 'echo-skip-seen'),
            'status' => 'good',
            'badge' => ['label' => __('Performance', 'echo-skip-seen'), 'color' => 'blue'],
            'description' => '',
            'actions' => '',
            'test' => self::TEST,
        ];

        $schema = $this->plugin->schema();
        if (!$schema->isReady() || !$schema->exists()) {
            $check = $schema->lastCheck();
            $missing = $check !== null && $check['missing'] !== [] ? implode(', ', $check['missing']) : __('table', 'echo-skip-seen');
            $result['label'] = __('The Echo Skip Seen table is missing or incomplete', 'echo-skip-seen');
            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['description'] = '<p>' . esc_html__('Every lookup passes through, so the importer downloads every article again. Missing:', 'echo-skip-seen')
                . ' ' . esc_html($missing) . '</p><p>' . esc_html__('Run "wp skip-seen verify-schema" to create or repair it.', 'echo-skip-seen') . '</p>';

            return $result;
        }

        try {
            $rows = $this->plugin->table()->count();
        } catch (\Throwable $e) {
            $result['label'] = __('The Echo Skip Seen table cannot be read', 'echo-skip-seen');
            $result['status'] = 'critical';
            $result['badge']['color'] = 'red';
            $result['description'] = '<p>' . esc_html($e->getMessage()) . '</p>';

            return $result;
        }

        $result['description'] = '<p>' . esc_html(sprintf(
            /* translators: %s: number of rows. */
            __('The table holds %s rows.', 'echo-skip-seen'),
            number_format_i18n($rows)
        )) . '</p>';

        if ($rows === 0 && $this->plugin->settings()->metaKeys() === []) {
            $result['status'] = 'recommended';
            $result['label'] = __('Echo Skip Seen has no source URL meta key and no rows', 'echo-skip-seen');
            $result['description'] .= '<p>' . esc_html__('Set the meta key that holds an imported post\'s source URL under Tools, Echo Skip Seen, then run the backfill.', 'echo-skip-seen') . '</p>';
        }

        $last = $this->plugin->counters()->snapshot()['last_24h'];
        if ($last['checked'] >= self::MIN_SAMPLE && $last['skipped'] / $last['checked'] > self::HIGH_SKIP_RATIO) {
            $result['description'] .= '<p>' . esc_html(sprintf(
                /* translators: 1: percentage, 2: number of checks. */
                __('For information: %1$s%% of the %2$s items checked in the last 24 hours were skipped. That is normal when feeds relist old articles. If new articles have stopped appearing, check a few URLs on the Tools page.', 'echo-skip-seen'),
                number_format_i18n(100 * $last['skipped'] / $last['checked'], 1),
                number_format_i18n($last['checked'])
            )) . '</p>';
        }

        return $result;
    }
}
