<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Keys;

use Closure;

/**
 * The rule set the canonical key is built from.
 *
 * A Rules object is plain data plus three optional callbacks, so the key
 * code can run and be tested without WordPress. Rules::fromFilters() builds
 * the set a WordPress site actually uses.
 */
final class Rules
{
    public const DEFAULT_MIRROR_LABELS = [
        'www', 'www1', 'www2', 'www3', 'ww', 'm', 'mobile', 'amp', 'wap', 'mob', 'touch', 'lite',
    ];

    public const DEFAULT_TRACKING_PARAMS = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'igshid', 'mc_cid', 'mc_eid', '_gl', 'ref', 'ref_src',
        'referrer', 'source', 'src', 'from', 'cmp', 'cmpid', 'intcmp', 'ocid', 'ncid', 's_cid', 'smid',
        'spm', 'share', 'shared', 'fromapp', 'pfrom', 'ito', 'tbref', 'sr_share', 'at_medium',
        'at_campaign', 'rss', 'rssfeed', 'feed', 'feedtype', 'outputtype', 'amp', '__twitter_impression',
    ];

    public const DEFAULT_ID_PARAMS = [
        'p', 'id', 'story', 'storyid', 'story_id', 'article', 'articleid', 'article_id', 'newsid', 'news_id',
        'nid', 'sid', 'aid', 'pid', 'prid', 'contentid', 'content_id', 'postid', 'post_id', 'v',
    ];

    public const DEFAULT_SCRIPT_EXTENSIONS = ['asp', 'aspx', 'php', 'jsp', 'cfm', 'cgi', 'do'];

    public const DEFAULT_AMP_SEGMENT_NAMES = ['articleshow'];

    public const DEFAULT_WRAPPER_HOSTS = ['news.google.com'];

    /** @var array<string, true> */
    public readonly array $mirrorLabels;

    /** @var array<string, true> */
    public readonly array $trackingParams;

    /** @var array<string, true> */
    public readonly array $idParams;

    /** @var array<string, true> */
    public readonly array $scriptExtensions;

    /** @var array<string, true> */
    public readonly array $ampSegmentNames;

    /**
     * @param list<string>          $mirrorLabels     Leading host labels removed (case 12).
     * @param list<string>          $trackingParams   Query names always dropped (case 27).
     * @param list<string>          $idParams         Query names kept on slug paths (case 30).
     * @param list<string>          $scriptExtensions Extensions that mark a script endpoint (case 31).
     * @param list<string>          $ampSegmentNames  Names an "amp_" prefix is removed from (case 24g).
     * @param array<string, string> $articleIdRules   Host to regular expression (case 37).
     * @param bool                  $useIntl          Convert Unicode hosts to punycode (case 11).
     * @param (Closure(string, string): string)|null $hostFilter      Final host part (case 16).
     * @param (Closure(string): bool)|null           $lowercasePath   Per-host path lower-casing (case 20); null lower-cases every host.
     * @param (Closure(string, string): string)|null $keyFilter       Final key (case 38).
     */
    public function __construct(
        array $mirrorLabels = self::DEFAULT_MIRROR_LABELS,
        array $trackingParams = self::DEFAULT_TRACKING_PARAMS,
        array $idParams = self::DEFAULT_ID_PARAMS,
        array $scriptExtensions = self::DEFAULT_SCRIPT_EXTENSIONS,
        array $ampSegmentNames = self::DEFAULT_AMP_SEGMENT_NAMES,
        public readonly array $articleIdRules = [],
        public readonly bool $useIntl = true,
        public readonly ?Closure $hostFilter = null,
        public readonly ?Closure $lowercasePath = null,
        public readonly ?Closure $keyFilter = null,
    ) {
        $this->mirrorLabels = self::set($mirrorLabels);
        $this->trackingParams = self::set($trackingParams);
        $this->idParams = self::set($idParams);
        $this->scriptExtensions = self::set($scriptExtensions);
        $this->ampSegmentNames = self::set($ampSegmentNames);
    }

    /**
     * Builds the rule set from the plugin's WordPress filters.
     */
    public static function fromFilters(): self
    {
        $rules = apply_filters('echo_skip_seen_article_id_rules', []);
        $clean = [];
        if (is_array($rules)) {
            foreach ($rules as $host => $pattern) {
                if (is_string($host) && $host !== '' && is_string($pattern) && $pattern !== '') {
                    $clean[$host] = $pattern;
                }
            }
        }

        return new self(
            self::stringList(apply_filters('echo_skip_seen_mirror_labels', self::DEFAULT_MIRROR_LABELS)),
            self::stringList(apply_filters('echo_skip_seen_tracking_params', self::DEFAULT_TRACKING_PARAMS)),
            self::stringList(apply_filters('echo_skip_seen_id_params', self::DEFAULT_ID_PARAMS)),
            self::stringList(apply_filters('echo_skip_seen_script_extensions', self::DEFAULT_SCRIPT_EXTENSIONS)),
            self::stringList(apply_filters('echo_skip_seen_amp_segment_names', self::DEFAULT_AMP_SEGMENT_NAMES)),
            $clean,
            function_exists('idn_to_ascii'),
            static function (string $host, string $url): string {
                $filtered = apply_filters('echo_skip_seen_host', $host, $url);

                return is_string($filtered) ? $filtered : $host;
            },
            static function (string $host): bool {
                return (bool) apply_filters('echo_skip_seen_lowercase_path', true, $host);
            },
            static function (string $key, string $url): string {
                $filtered = apply_filters('echo_skip_seen_canonical_key', $key, $url);

                return is_string($filtered) ? $filtered : $key;
            }
        );
    }

    /**
     * Normalises a filter result into a list of non-empty strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $items
     * @return array<string, true>
     */
    private static function set(array $items): array
    {
        $set = [];
        foreach ($items as $item) {
            $set[Ascii::lower(ltrim($item, '.'))] = true;
        }

        return $set;
    }
}
