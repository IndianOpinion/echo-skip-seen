<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Source of the current Unix time. Injected so tests can move time.
 */
interface Clock
{
    public function now(): int;
}
