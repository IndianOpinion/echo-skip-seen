<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Reports a failure the plugin absorbed: counter, debug log line, action.
 */
final class FailOpen
{
    public function __construct(private readonly Counters $counters, private readonly Log $log)
    {
    }

    public function report(string $where, string $reason, string $url = '', bool $log = true): void
    {
        $this->counters->increment('failed_open');
        if ($log) {
            $this->log->debug('failed open in ' . $where . ': ' . $reason, $url);
        }

        try {
            do_action('echo_skip_seen_failed_open', $where, $reason);
        } catch (\Throwable) {
            // A listener that throws must not turn a fail-open into a failure.
            return;
        }
    }
}
