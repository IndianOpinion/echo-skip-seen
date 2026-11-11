<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Operating counters (PRD 4.12).
 *
 * Increments stay in memory and are flushed once, at shutdown, in one
 * option write. A lost flush loses one request's counts; counters are an
 * operating aid, not an audit log. Hourly buckets of checked and skipped
 * over the last day feed the Site Health ratio note.
 *
 * @phpstan-type Snapshot array{
 *     checked: int,
 *     skipped: int,
 *     recorded: int,
 *     failed_open: int,
 *     since: array<string, int>,
 *     skip_rate: float|null,
 *     last_24h: array{checked: int, skipped: int}
 * }
 */
final class Counters
{
    public const OPTION = 'echo_skip_seen_counters';

    public const NAMES = ['checked', 'skipped', 'recorded', 'failed_open'];

    private const BUCKET_HOURS = 24;

    /** @var array<string, int> */
    private array $pending = [];

    /** @var array<int, array{checked: int, skipped: int}> */
    private array $pendingBuckets = [];

    private bool $shutdownHooked = false;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function increment(string $name, int $by = 1): void
    {
        if ($by <= 0 || !in_array($name, self::NAMES, true)) {
            return;
        }

        $this->pending[$name] = ($this->pending[$name] ?? 0) + $by;

        if ($name === 'checked' || $name === 'skipped') {
            $hour = intdiv($this->clock->now(), 3600);
            $bucket = $this->pendingBuckets[$hour] ?? ['checked' => 0, 'skipped' => 0];
            $bucket[$name] += $by;
            $this->pendingBuckets[$hour] = $bucket;
        }

        if (!$this->shutdownHooked) {
            $this->shutdownHooked = true;
            add_action('shutdown', [$this, 'flush']);
        }
    }

    /**
     * Writes pending increments in one option write. Never throws.
     */
    public function flush(): void
    {
        if ($this->pending === [] && $this->pendingBuckets === []) {
            return;
        }

        try {
            $stored = $this->stored();
            foreach ($this->pending as $name => $count) {
                $stored['counts'][$name] = ($stored['counts'][$name] ?? 0) + $count;
            }
            foreach ($this->pendingBuckets as $hour => $bucket) {
                $current = $stored['hourly'][$hour] ?? ['checked' => 0, 'skipped' => 0];
                $stored['hourly'][$hour] = [
                    'checked' => $current['checked'] + $bucket['checked'],
                    'skipped' => $current['skipped'] + $bucket['skipped'],
                ];
            }
            $stored['hourly'] = $this->trimBuckets($stored['hourly']);

            $this->write($stored);
        } catch (\Throwable) {
            // Counters never interrupt a request.
        }

        $this->pending = [];
        $this->pendingBuckets = [];
    }

    /**
     * Stored counts plus anything not yet flushed.
     *
     * @return Snapshot
     */
    public function snapshot(): array
    {
        $stored = $this->stored();
        $counts = [];
        foreach (self::NAMES as $name) {
            $counts[$name] = ($stored['counts'][$name] ?? 0) + ($this->pending[$name] ?? 0);
        }

        $hourly = $stored['hourly'];
        foreach ($this->pendingBuckets as $hour => $bucket) {
            $current = $hourly[$hour] ?? ['checked' => 0, 'skipped' => 0];
            $hourly[$hour] = [
                'checked' => $current['checked'] + $bucket['checked'],
                'skipped' => $current['skipped'] + $bucket['skipped'],
            ];
        }
        $last = ['checked' => 0, 'skipped' => 0];
        foreach ($this->trimBuckets($hourly) as $bucket) {
            $last['checked'] += $bucket['checked'];
            $last['skipped'] += $bucket['skipped'];
        }

        return [
            'checked' => $counts['checked'],
            'skipped' => $counts['skipped'],
            'recorded' => $counts['recorded'],
            'failed_open' => $counts['failed_open'],
            'since' => $stored['since'],
            'skip_rate' => $counts['checked'] > 0 ? round($counts['skipped'] / $counts['checked'], 4) : null,
            'last_24h' => $last,
        ];
    }

    public function reset(): void
    {
        $this->pending = [];
        $this->pendingBuckets = [];
        $now = $this->clock->now();
        $this->write([
            'counts' => array_fill_keys(self::NAMES, 0),
            'since' => array_fill_keys(self::NAMES, $now),
            'hourly' => [],
        ]);
    }

    /**
     * @return array{counts: array<string, int>, since: array<string, int>, hourly: array<int, array{checked: int, skipped: int}>}
     */
    private function stored(): array
    {
        $value = get_option(self::OPTION, []);
        $value = is_array($value) ? $value : [];
        $now = $this->clock->now();

        $counts = [];
        $since = [];
        foreach (self::NAMES as $name) {
            $counts[$name] = (int) ($value['counts'][$name] ?? 0);
            $since[$name] = (int) ($value['since'][$name] ?? $now);
        }

        $hourly = [];
        foreach ((array) ($value['hourly'] ?? []) as $hour => $bucket) {
            if (is_array($bucket)) {
                $hourly[(int) $hour] = [
                    'checked' => (int) ($bucket['checked'] ?? 0),
                    'skipped' => (int) ($bucket['skipped'] ?? 0),
                ];
            }
        }

        return ['counts' => $counts, 'since' => $since, 'hourly' => $hourly];
    }

    /**
     * @param array<int, array{checked: int, skipped: int}> $hourly
     * @return array<int, array{checked: int, skipped: int}>
     */
    private function trimBuckets(array $hourly): array
    {
        $oldest = intdiv($this->clock->now(), 3600) - self::BUCKET_HOURS + 1;
        foreach (array_keys($hourly) as $hour) {
            if ($hour < $oldest) {
                unset($hourly[$hour]);
            }
        }
        ksort($hourly);

        return $hourly;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function write(array $value): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $value, '', false);
        } else {
            update_option(self::OPTION, $value, false);
        }
    }
}
