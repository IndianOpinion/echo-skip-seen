<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

/**
 * Both keys of one URL and their MD5 hashes (lower-case hex).
 */
final class KeySet
{
    public const KIND_CANONICAL = 1;
    public const KIND_PLAIN = 2;

    public readonly string $canonicalHash;
    public readonly string $plainHash;

    public function __construct(
        public readonly string $canonical,
        public readonly string $plain,
        public readonly int $version,
    ) {
        $this->canonicalHash = md5($canonical);
        $this->plainHash = md5($plain);
    }

    /**
     * Rows to write: one per distinct hash (PRD 53).
     *
     * @return list<array{hash: string, kind: int, version: int}>
     */
    public function rows(): array
    {
        $rows = [['hash' => $this->canonicalHash, 'kind' => self::KIND_CANONICAL, 'version' => $this->version]];
        if ($this->plainHash !== $this->canonicalHash) {
            $rows[] = ['hash' => $this->plainHash, 'kind' => self::KIND_PLAIN, 'version' => 0];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function hashes(): array
    {
        return array_values(array_unique([$this->canonicalHash, $this->plainHash]));
    }

    public static function kindName(int $kind): string
    {
        return $kind === self::KIND_PLAIN ? 'plain' : 'canonical';
    }

    /**
     * @return array{canonical: string, plain: string, canonical_hash: string, plain_hash: string, version: int}
     */
    public function toArray(): array
    {
        return [
            'canonical' => $this->canonical,
            'plain' => $this->plain,
            'canonical_hash' => $this->canonicalHash,
            'plain_hash' => $this->plainHash,
            'version' => $this->version,
        ];
    }
}
