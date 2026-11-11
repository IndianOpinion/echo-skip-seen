<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;

/**
 * Deletes expired rows in batches (PRD 4.9).
 */
final class Retention
{
    public const HOOK = 'echo_skip_seen_prune';

    public const BATCH = 5000;

    /** Seconds one run may spend before it stops and leaves the rest for the next run. */
    public const BUDGET_SECONDS = 5.0;

    public function __construct(
        private readonly Table $table,
        private readonly Schema $schema,
        private readonly Settings $settings,
        private readonly FailOpen $failOpen,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Returns the number of rows deleted. Never throws.
     */
    public function prune(int $batch = self::BATCH, float $budget = self::BUDGET_SECONDS): int
    {
        try {
            $days = $this->settings->retentionDays();
            if ($days === 0 || !$this->schema->isReady()) {
                return 0;
            }

            $cutoff = $this->clock->now() - $days * 86400;
            $started = microtime(true);
            $deleted = 0;
            do {
                $count = $this->table->deleteOlderThan($cutoff, $batch);
                $deleted += $count;
            } while ($count >= $batch && microtime(true) - $started < $budget);

            return $deleted;
        } catch (\Throwable $e) {
            $this->failOpen->report('prune', $e->getMessage());

            return 0;
        }
    }
}
