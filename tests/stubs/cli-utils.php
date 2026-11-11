<?php

declare(strict_types=1);

namespace WP_CLI\Utils;

// phpcs:disable PSR1.Files.SideEffects

final class FakeProgressBar
{
    public int $ticks = 0;

    public function tick(int $by = 1): void
    {
        $this->ticks += $by;
    }

    public function finish(): void
    {
    }
}

function wp_clear_object_cache(): void
{
    \WpStubs::$objectCacheClears++;
}

function make_progress_bar(string $message, int $count): FakeProgressBar
{
    return new FakeProgressBar();
}

/**
 * @param list<array<string, string>> $items
 * @param list<string> $fields
 */
function format_items(string $format, array $items, array $fields): void
{
    if ($format === 'json') {
        \WpStubs::$cliOutput[] = (string) json_encode($items);

        return;
    }
    foreach ($items as $item) {
        $line = [];
        foreach ($fields as $field) {
            $line[] = $item[$field] ?? '';
        }
        \WpStubs::$cliOutput[] = implode("\t", $line);
    }
}
