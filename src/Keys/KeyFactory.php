<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

use Closure;
use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;

/**
 * Computes the KeySet for a URL. Rules are built once, on first use.
 */
final class KeyFactory
{
    private ?Canonicalizer $canonicalizer = null;

    /**
     * @param Closure(): Rules $rules
     */
    public function __construct(
        private readonly Closure $rules,
        private readonly int $version = Canonicalizer::VERSION,
    ) {
    }

    /**
     * @throws InvalidUrlException
     */
    public function forUrl(string $url): KeySet
    {
        $clean = Url::clean($url);
        Url::parse($clean);

        return new KeySet($this->canonicalizer()->key($url), PlainKey::make($clean), $this->version);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function canonicalizer(): Canonicalizer
    {
        if ($this->canonicalizer === null) {
            $this->canonicalizer = new Canonicalizer(($this->rules)());
        }

        return $this->canonicalizer;
    }
}
