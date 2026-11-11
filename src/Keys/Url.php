<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;

/**
 * Input cleaning and parsing shared by both key kinds (PRD 4.2 cases 1 to 5).
 */
final class Url
{
    /**
     * URLs longer than this many bytes are refused (and the lookup fails open).
     */
    public const MAX_BYTES = 4096;

    /**
     * True when the value, once trimmed, starts with http:// or https://.
     */
    public static function looksLikeHttp(string $value): bool
    {
        return preg_match('~^\s*https?://~i', $value) === 1;
    }

    /**
     * True for an http or https URL that parses with a host.
     */
    public static function isHttpUrl(string $value): bool
    {
        try {
            self::parse(self::clean($value));
        } catch (InvalidUrlException) {
            return false;
        }

        return true;
    }

    /**
     * Trims, checks the length, decodes HTML entities once and checks the scheme.
     *
     * @throws InvalidUrlException
     */
    public static function clean(string $value): string
    {
        $value = trim($value);
        if (strlen($value) > self::MAX_BYTES) {
            throw new InvalidUrlException('URL longer than ' . self::MAX_BYTES . ' bytes');
        }

        // Case 2: exactly one decoding pass.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (!self::looksLikeHttp($value)) {
            throw new InvalidUrlException('not an http or https URL');
        }

        return $value;
    }

    /**
     * @return array{host: string, port: int|null, path: string, query: string}
     *
     * @throws InvalidUrlException
     */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidUrlException('URL has no host');
        }

        return [
            'host' => $parts['host'],
            'port' => $parts['port'] ?? null,
            'path' => $parts['path'] ?? '',
            'query' => $parts['query'] ?? '',
        ];
    }

    /**
     * The host of a URL without parsing anything else, lower-cased, or '' when there is none.
     */
    public static function host(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) ? Ascii::lower(rtrim($host, '.')) : '';
    }

    /**
     * Removes user information from a URL so it can be written to a log.
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('~^(\s*[a-z][a-z0-9+.\-]*://)[^/?#@]*@~i', '$1', $url);
    }
}
