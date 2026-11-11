<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Keys\Url;

/**
 * Debug logging through the PHP error log. Off unless the setting is on.
 */
final class Log
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function debug(string $message, string $url = ''): void
    {
        try {
            if (!$this->settings->debug()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $line = '[echo-skip-seen] ' . $message;
        if ($url !== '') {
            $line .= ' url=' . substr(Url::redact($url), 0, 300);
        }

        error_log($line);
    }
}
