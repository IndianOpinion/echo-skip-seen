<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

use IndianOpinion\EchoSkipSeen\Exception\InvalidUrlException;

/**
 * Builds the canonical key: the normalised identity of one article.
 *
 * The key is host, path and a filtered query. The scheme, user information,
 * default ports and the fragment are never part of it. Every step below
 * maps to a numbered case in section 4.2 of docs/PRD.md.
 */
final class Canonicalizer
{
    /**
     * Version of the default rule set. Changing any default rule means
     * raising this number (PRD 40 and lesson 7).
     */
    public const VERSION = 1;

    private const AMP_CACHE_SUFFIX = '.cdn.ampproject.org';

    private const INDEX_SEGMENTS = ['index.html', 'index.htm', 'index.php'];

    public function __construct(private readonly Rules $rules)
    {
    }

    /**
     * @throws InvalidUrlException When the value is not an http or https URL with a host.
     */
    public function key(string $url): string
    {
        $clean = Url::clean($url);
        $key = $this->build($clean, true);

        if ($this->rules->keyFilter !== null) {
            $filtered = ($this->rules->keyFilter)($key, $url);
            if ($filtered !== '') {
                $key = $filtered;
            }
        }

        return $key;
    }

    /**
     * Host normalisation without the host filter: cases 9 to 14.
     *
     * Used for the canonical key and for the "own host" comparison.
     */
    public function normaliseHost(string $host): string
    {
        $host = rtrim($host, '.');

        if (preg_match('/[\x80-\xFF]/', $host) === 1) {
            $host = $this->unicodeHost($host);
        }

        $host = Ascii::lower($host);

        if ($host === '' || self::isIpHost($host)) {
            return $host;
        }

        $labels = explode('.', $host);
        while (count($labels) > 2 && isset($this->rules->mirrorLabels[$labels[0]])) {
            array_shift($labels);
        }

        return implode('.', $labels);
    }

    public static function isIpHost(string $host): bool
    {
        return str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private function build(string $url, bool $allowUnwrap): string
    {
        $parts = Url::parse($url);

        $rawHost = Ascii::lower(rtrim($parts['host'], '.'));

        // Case 15: Google AMP cache URLs carry the publisher URL in the path.
        if ($allowUnwrap && str_ends_with($rawHost, self::AMP_CACHE_SUFFIX)) {
            $inner = self::unwrapAmpCache($parts['path'], $parts['query']);
            if ($inner !== null) {
                return $this->build($inner, false);
            }
        }

        $host = $this->normaliseHost($parts['host']);
        if ($this->rules->hostFilter !== null) {
            $filtered = Ascii::lower(trim(($this->rules->hostFilter)($host, $url)));
            if ($filtered !== '') {
                $host = $filtered;
            }
        }

        // Cases 7 and 8: the scheme is not in the key, so both default ports go.
        $hostPart = $host;
        if ($parts['port'] !== null && $parts['port'] !== 80 && $parts['port'] !== 443) {
            $hostPart .= ':' . $parts['port'];
        }

        // Case 20: on by default; the callback can turn it off per host.
        $lowercase = $this->rules->lowercasePath === null || ($this->rules->lowercasePath)($host);
        [$path, $isScript] = $this->path($parts['path'], $lowercase);

        $idKey = $this->articleId($host, $path);
        if ($idKey !== null) {
            return $hostPart . '/#id=' . $idKey;
        }

        $query = $this->query($parts['query'], $isScript);

        return $hostPart . $path . ($query === '' ? '' : '?' . $query);
    }

    /**
     * Cases 17 to 25. Returns the path and whether it is a script endpoint.
     *
     * @return array{0: string, 1: bool}
     */
    private function path(string $rawPath, bool $lowercase): array
    {
        $path = PercentEncoding::normalise($rawPath);
        if ($lowercase) {
            $path = self::lowerKeepingEscapes($path);
        }

        // Case 25 then 21 and 22: resolve dot segments; dropping empty
        // segments collapses repeated slashes and removes a trailing slash.
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $last = $segments === [] ? '' : $segments[count($segments) - 1];
        $isScriptFile = $this->isScriptFile($last);

        $segments = self::stripIndex($segments);
        $segments = $this->stripAmp($segments);
        $segments = self::stripIndex($segments);

        $normalised = '/' . implode('/', $segments);

        return [$normalised, $isScriptFile || $normalised === '/'];
    }

    private function isScriptFile(string $segment): bool
    {
        $dot = strrpos($segment, '.');
        if ($dot === false) {
            return false;
        }

        return isset($this->rules->scriptExtensions[Ascii::lower(substr($segment, $dot + 1))]);
    }

    /**
     * Case 23.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private static function stripIndex(array $segments): array
    {
        $count = count($segments);
        if ($count > 0 && in_array(Ascii::lower($segments[$count - 1]), self::INDEX_SEGMENTS, true)) {
            array_pop($segments);
        }

        return $segments;
    }

    /**
     * Case 24. Only whole segments and fixed suffixes are touched, so a
     * segment that merely contains "amp" is never changed.
     *
     * @param list<string> $segments
     * @return list<string>
     */
    private function stripAmp(array $segments): array
    {
        $count = count($segments);

        // 24d: a trailing "/amp/1" pair.
        if ($count >= 2 && Ascii::lower($segments[$count - 2]) === 'amp' && $segments[$count - 1] === '1') {
            array_splice($segments, $count - 2, 2);
        }

        // 24a, 24b, 24c: a whole "amp" segment anywhere.
        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => Ascii::lower($segment) !== 'amp'
        ));

        $count = count($segments);
        if ($count > 0) {
            $last = $segments[$count - 1];
            $lower = Ascii::lower($last);
            if (str_ends_with($lower, '.amp.html') && strlen($last) > 9) {
                // 24f.
                $segments[$count - 1] = substr($last, 0, -9) . '.html';
            } elseif (str_ends_with($lower, '-amp.html') && strlen($last) > 9) {
                // 24f.
                $segments[$count - 1] = substr($last, 0, -9) . '.html';
            } elseif (str_ends_with($lower, '.amp') && strlen($last) > 4) {
                // 24e.
                $segments[$count - 1] = substr($last, 0, -4);
            }
        }

        // 24g: "amp_" prefix on a known article segment name.
        foreach ($segments as $index => $segment) {
            if (Ascii::startsWithIgnoreCase($segment, 'amp_')) {
                $rest = substr($segment, 4);
                if (isset($this->rules->ampSegmentNames[Ascii::lower($rest)])) {
                    $segments[$index] = $rest;
                }
            }
        }

        return $segments;
    }

    /**
     * Case 37: a site-supplied rule that turns a path into a stable id.
     */
    private function articleId(string $host, string $path): ?string
    {
        foreach ($this->rules->articleIdRules as $ruleHost => $pattern) {
            if ($this->normaliseHost($ruleHost) !== $host) {
                continue;
            }

            $match = Regex::match($pattern, $path);
            if ($match === null) {
                continue;
            }

            $id = $match['id'] ?? $match[1] ?? '';
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return null;
    }

    /**
     * Cases 26 to 35.
     */
    private function query(string $rawQuery, bool $isScript): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $params = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            $equals = strpos($pair, '=');
            $name = $equals === false ? $pair : substr($pair, 0, $equals);
            $value = $equals === false ? '' : substr($pair, $equals + 1);

            $name = Ascii::lower(PercentEncoding::normalise($name));
            if ($name === '' || str_contains($name, '[') || str_contains($name, '%5B')) {
                continue;
            }
            if (str_starts_with($name, 'utm_') || isset($this->rules->trackingParams[$name])) {
                continue;
            }

            // Case 34: the last value wins, as when PHP reads a query string.
            $params[$name] = PercentEncoding::normalise($value);
        }

        $kept = [];
        foreach ($params as $name => $value) {
            $name = (string) $name;
            if ($value === '') {
                continue;
            }
            if (!$isScript && !isset($this->rules->idParams[$name])) {
                continue;
            }
            $kept[$name] = $value;
        }

        ksort($kept, SORT_STRING);

        $pairs = [];
        foreach ($kept as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('&', $pairs);
    }

    private function unicodeHost(string $host): string
    {
        if ($this->rules->useIntl && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($host, 'UTF-8');
        }

        return $host;
    }

    private static function unwrapAmpCache(string $path, string $query): ?string
    {
        if (preg_match('~^/[cvi]/(?:s/)?([^/]+)(/.*)?$~', $path, $match) !== 1) {
            return null;
        }

        $inner = 'https://' . $match[1] . ($match[2] ?? '/');

        return $inner . ($query === '' ? '' : '?' . $query);
    }

    private static function lowerKeepingEscapes(string $path): string
    {
        $lower = Ascii::lower($path);

        return (string) preg_replace_callback(
            '/%[0-9a-f]{2}/',
            static fn (array $m): string => Ascii::upper($m[0]),
            $lower
        );
    }
}
