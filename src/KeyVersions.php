<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * Tracks which canonical key versions a lookup accepts (PRD 99).
 *
 * When the code's key version changes, rows written under the previous
 * version keep matching for one retention period, then age out. Older
 * versions never match. Plain rows are version independent.
 */
final class KeyVersions
{
    /** Autoloaded: small, and read on init. */
    public const OPTION = 'echo_skip_seen_key_versions';

    public function __construct(private readonly int $current, private readonly Clock $clock)
    {
    }

    /**
     * Records the code's key version, remembering the previous one and when it changed.
     */
    public function sync(): void
    {
        $state = $this->state();
        if ($state !== null && $state['current'] === $this->current) {
            return;
        }

        $new = [
            'current' => $this->current,
            'previous' => $state === null ? null : $state['current'],
            'since' => $this->clock->now(),
        ];
        update_option(self::OPTION, $new, true);
    }

    /**
     * @return list<int>
     */
    public function accepted(int $retentionDays): array
    {
        $accepted = [$this->current];
        $state = $this->state();
        if ($state === null || $state['current'] !== $this->current || $state['previous'] === null) {
            return $accepted;
        }

        $window = $retentionDays * 86400;
        if ($retentionDays === 0 || $this->clock->now() - $state['since'] <= $window) {
            $accepted[] = $state['previous'];
        }

        return $accepted;
    }

    /**
     * @return array{current: int, previous: int|null, since: int}|null
     */
    private function state(): ?array
    {
        $value = get_option(self::OPTION, null);
        if (!is_array($value) || !isset($value['current'], $value['since'])) {
            return null;
        }

        return [
            'current' => (int) $value['current'],
            'previous' => isset($value['previous']) ? (int) $value['previous'] : null,
            'since' => (int) $value['since'],
        ];
    }
}
