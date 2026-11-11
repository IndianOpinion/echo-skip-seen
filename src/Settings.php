<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

/**
 * The plugin's settings: one option array, not autoloaded, read on first use.
 */
final class Settings
{
    public const OPTION = 'echo_skip_seen_settings';

    public const MAX_RETENTION_DAYS = 3650;

    public const DEFAULT_RETENTION_DAYS = 90;

    /** Characters accepted in a meta key name. */
    public const META_KEY_PATTERN = '/^[A-Za-z0-9_\-.:]{1,255}$/';

    /** @var array{enabled: bool, retention_days: int, meta_keys: list<string>, debug: bool}|null */
    private ?array $cache = null;

    private int $site = 0;

    /**
     * @return array{enabled: bool, retention_days: int, meta_keys: list<string>, debug: bool}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'meta_keys' => [],
            'debug' => false,
        ];
    }

    /**
     * @return array{enabled: bool, retention_days: int, meta_keys: list<string>, debug: bool}
     */
    public function all(): array
    {
        $site = get_current_blog_id();
        if ($this->cache === null || $site !== $this->site) {
            $this->site = $site;
            $stored = get_option(self::OPTION, []);
            $this->cache = self::sanitise(is_array($stored) ? $stored : []);
        }

        return $this->cache;
    }

    /**
     * Validates and stores new settings. Unknown keys are ignored.
     *
     * @param array<string, mixed> $input
     */
    public function save(array $input): void
    {
        $clean = self::sanitise(array_merge($this->all(), $input));
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, $clean, '', false);
        } else {
            update_option(self::OPTION, $clean, false);
        }
        $this->cache = $clean;
        $this->site = get_current_blog_id();
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Master switch: the constant wins, then the setting, then the filter.
     */
    public function enabled(): bool
    {
        if (defined('ECHO_SKIP_SEEN_DISABLED') && constant('ECHO_SKIP_SEEN_DISABLED')) {
            return false;
        }

        return (bool) apply_filters('echo_skip_seen_enabled', $this->all()['enabled']);
    }

    public function debug(): bool
    {
        return $this->all()['debug'];
    }

    public function retentionDays(): int
    {
        $days = apply_filters('echo_skip_seen_retention_days', $this->all()['retention_days']);

        return self::clampDays($days);
    }

    /**
     * The ordered list of meta keys that hold an imported post's source URL.
     *
     * @return list<string>
     */
    public function metaKeys(): array
    {
        $keys = apply_filters('echo_skip_seen_source_meta_keys', $this->all()['meta_keys']);

        return self::sanitiseMetaKeys($keys);
    }

    /**
     * @return list<string>
     */
    public function postTypes(): array
    {
        $types = apply_filters('echo_skip_seen_post_types', ['post']);
        $out = [];
        if (is_array($types)) {
            foreach ($types as $type) {
                if (is_string($type) && preg_match('/^[a-z0-9_\-]{1,20}$/', $type) === 1) {
                    $out[] = $type;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<mixed> $input
     * @return array{enabled: bool, retention_days: int, meta_keys: list<string>, debug: bool}
     */
    public static function sanitise(array $input): array
    {
        $defaults = self::defaults();

        return [
            'enabled' => isset($input['enabled']) ? self::bool($input['enabled']) : $defaults['enabled'],
            'retention_days' => isset($input['retention_days'])
                ? self::clampDays($input['retention_days'])
                : $defaults['retention_days'],
            'meta_keys' => isset($input['meta_keys']) ? self::sanitiseMetaKeys($input['meta_keys']) : [],
            'debug' => isset($input['debug']) ? self::bool($input['debug']) : $defaults['debug'],
        ];
    }

    /**
     * Accepts a list or a string with one key per line or comma.
     *
     * @return list<string>
     */
    public static function sanitiseMetaKeys(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key) {
            if (!is_string($key)) {
                continue;
            }
            $key = trim($key);
            if (preg_match(self::META_KEY_PATTERN, $key) === 1 && !in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    public static function clampDays(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^\d+$/', $value) !== 1) {
                return self::DEFAULT_RETENTION_DAYS;
            }
        }
        if (!is_int($value) && !is_string($value) && !is_float($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return max(0, min(self::MAX_RETENTION_DAYS, (int) $value));
    }

    private static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }
}
