<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Tests\Support;

use IndianOpinion\EchoSkipSeen\Clock;

final class FixedClock implements Clock
{
    public function __construct(public int $time = 1_790_000_000)
    {
    }

    public function now(): int
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time += $seconds;
    }
}
