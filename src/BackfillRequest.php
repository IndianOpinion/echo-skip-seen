<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Parameters of one backfill batch.
 */
final class BackfillRequest
{
    public const DEFAULT_BATCH = 500;
    public const MAX_BATCH = 5000;

    public readonly int $batchSize;

    /**
     * @param list<string>      $postTypes Empty means the configured post types.
     * @param list<string>|null $metaKeys  Null means the configured meta keys.
     */
    public function __construct(
        public readonly int $afterId = 0,
        int $batchSize = self::DEFAULT_BATCH,
        public readonly array $postTypes = [],
        public readonly ?int $since = null,
        public readonly bool $includeExpired = false,
        public readonly bool $dryRun = false,
        public readonly ?array $metaKeys = null,
    ) {
        $this->batchSize = max(1, min(self::MAX_BATCH, $batchSize));
    }

    public function withAfterId(int $afterId): self
    {
        return new self(
            $afterId,
            $this->batchSize,
            $this->postTypes,
            $this->since,
            $this->includeExpired,
            $this->dryRun,
            $this->metaKeys
        );
    }
}
