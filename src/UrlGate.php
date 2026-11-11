<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;
use IndianOpinion\EchoSkipSeen\Keys\Url;

/**
 * Decides whether a URL is one the plugin should key at all (PRD 4.4).
 *
 * Runs the public pre-processing filter, then leaves alone anything that
 * is not an http or https URL, any URL on a wrapper host (left to an
 * unwrap tool), and any URL on the site's own host.
 */
final class UrlGate
{
    /** @var array<string, true>|null */
    private ?array $wrapperHosts = null;

    /** @var array<int, string> Site id to normalised home host. */
    private array $ownHosts = [];

    public function __construct(private readonly KeyFactory $keys)
    {
    }

    /**
     * Returns the URL to key, or null when the plugin must leave it alone.
     *
     * A callback on the pre-processing filter that throws is not caught
     * here; the caller fails open.
     *
     * @param 'lookup'|'record' $context
     */
    public function admit(string $url, string $context): ?string
    {
        $filtered = apply_filters('echo_skip_seen_url', $url, $context);
        if (is_string($filtered) && trim($filtered) !== '') {
            $url = $filtered;
        }

        if (!Url::looksLikeHttp($url)) {
            return null;
        }

        $host = Url::host(html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($host === '') {
            // No host: the key factory reports it as invalid.
            return $url;
        }

        if (isset($this->wrapperHosts()[$host])) {
            return null;
        }

        if ($this->isOwnHost($host)) {
            return null;
        }

        return $url;
    }

    public function isOwnHost(string $host): bool
    {
        $own = $this->ownHost();
        if ($own === '') {
            return false;
        }

        return $this->keys->canonicalizer()->normaliseHost($host) === $own;
    }

    private function ownHost(): string
    {
        $site = get_current_blog_id();
        if (!isset($this->ownHosts[$site])) {
            $host = Url::host((string) home_url('/'));
            $this->ownHosts[$site] = $host === '' ? '' : $this->keys->canonicalizer()->normaliseHost($host);
        }

        return $this->ownHosts[$site];
    }

    /**
     * @return array<string, true>
     */
    private function wrapperHosts(): array
    {
        if ($this->wrapperHosts === null) {
            $hosts = Rules::stringList(apply_filters('echo_skip_seen_wrapper_hosts', Rules::DEFAULT_WRAPPER_HOSTS));
            $this->wrapperHosts = [];
            foreach ($hosts as $host) {
                $this->wrapperHosts[strtolower(rtrim($host, '.'))] = true;
            }
        }

        return $this->wrapperHosts;
    }
}
