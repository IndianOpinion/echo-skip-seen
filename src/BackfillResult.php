<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Totals of one or more backfill batches.
 */
final class BackfillResult
{
    public int $scanned = 0;
    public int $withUrl = 0;
    public int $written = 0;
    public int $present = 0;
    public int $rejected = 0;
    public int $expired = 0;
    public int $lastId = 0;
    public bool $done = false;

    public function add(self $other): void
    {
        $this->scanned += $other->scanned;
        $this->withUrl += $other->withUrl;
        $this->written += $other->written;
        $this->present += $other->present;
        $this->rejected += $other->rejected;
        $this->expired += $other->expired;
        $this->lastId = max($this->lastId, $other->lastId);
        $this->done = $other->done;
    }

    /**
     * @return array{scanned: int, with_url: int, written: int, present: int, rejected: int, expired: int, last_id: int, done: bool}
     */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'with_url' => $this->withUrl,
            'written' => $this->written,
            'present' => $this->present,
            'rejected' => $this->rejected,
            'expired' => $this->expired,
            'last_id' => $this->lastId,
            'done' => $this->done,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $result = new self();
        $result->scanned = (int) ($data['scanned'] ?? 0);
        $result->withUrl = (int) ($data['with_url'] ?? 0);
        $result->written = (int) ($data['written'] ?? 0);
        $result->present = (int) ($data['present'] ?? 0);
        $result->rejected = (int) ($data['rejected'] ?? 0);
        $result->expired = (int) ($data['expired'] ?? 0);
        $result->lastId = (int) ($data['last_id'] ?? 0);
        $result->done = (bool) ($data['done'] ?? false);

        return $result;
    }
}
