<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Admin;

use IndianOpinion\EchoSkipSeen\Plugin;

/**
 * Explains one URL: both keys, both hashes, presence and the lookup answer.
 * Shared by the admin "check a URL" box and "wp skip-seen check".
 */
final class UrlReport
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    /**
     * @return array{keys: array{canonical: string, plain: string, canonical_hash: string, plain_hash: string, version: int}|null, canonical_present: string, plain_present: string, seen: bool, note: string}
     */
    public function build(string $url): array
    {
        $report = ['keys' => null, 'canonical_present' => 'no', 'plain_present' => 'no', 'seen' => false, 'note' => ''];

        try {
            $keys = $this->plugin->recorder()->keysFor($url);
        } catch (\Throwable $e) {
            $report['note'] = 'No key: ' . $e->getMessage();

            return $report;
        }

        if ($keys === null) {
            $report['note'] = 'Left alone: not an http or https URL, a wrapper host, or this site\'s own host.';

            return $report;
        }

        $report['keys'] = $keys->toArray();

        try {
            $found = [];
            foreach ($this->plugin->table()->find($keys->hashes()) as $row) {
                $found[$row['hash']] = $row;
            }
            foreach (['canonical' => $keys->canonicalHash, 'plain' => $keys->plainHash] as $kind => $hash) {
                if (isset($found[$hash])) {
                    $row = $found[$hash];
                    $report[$kind . '_present'] = sprintf(
                        'present: post %d, last seen %s UTC, version %d',
                        $row['post_id'],
                        gmdate('Y-m-d H:i', $row['last_seen']),
                        $row['version']
                    );
                }
            }
        } catch (\Throwable $e) {
            $report['canonical_present'] = $report['plain_present'] = 'unknown: ' . $e->getMessage();
        }

        $report['seen'] = $this->plugin->lookup()->isSeen($url);

        return $report;
    }
}
