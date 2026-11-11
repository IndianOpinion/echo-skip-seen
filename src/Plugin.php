<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen;

use IndianOpinion\EchoSkipSeen\Admin\AdminPage;
use IndianOpinion\EchoSkipSeen\Admin\SiteHealth;
use IndianOpinion\EchoSkipSeen\Cli\Command;
use IndianOpinion\EchoSkipSeen\Db\Db;
use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Db\Table;
use IndianOpinion\EchoSkipSeen\Keys\Canonicalizer;
use IndianOpinion\EchoSkipSeen\Keys\KeyFactory;
use IndianOpinion\EchoSkipSeen\Keys\Rules;

/**
 * Wires the plugin into WordPress and holds its services.
 *
 * Registering hooks costs no query and no option read. Services are built
 * on first use, so a front-end page view that imports nothing does no
 * work beyond the autoloaded schema version check on init.
 */
final class Plugin
{
    public const VERSION = '0.1.0';

    public const DEFAULT_PRIORITY = 10;

    private static ?self $instance = null;

    private ?Settings $settings = null;
    private ?Counters $counters = null;
    private ?Log $log = null;
    private ?FailOpen $failOpen = null;
    private ?KeyFactory $keys = null;
    private ?UrlGate $gate = null;
    private ?Db $db = null;
    private ?Table $table = null;
    private ?Schema $schema = null;
    private ?KeyVersions $versions = null;
    private ?Lookup $lookup = null;
    private ?Recorder $recorder = null;
    private ?PostWatcher $watcher = null;
    private ?Backfill $backfill = null;
    private ?Retention $retention = null;
    private ?Installer $installer = null;

    public function __construct(
        private readonly string $file,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $keyVersion = Canonicalizer::VERSION,
    ) {
    }

    /**
     * Creates the instance and registers its hooks. Called once by the plugin file.
     */
    public static function boot(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->register();
        }

        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Replaces the shared instance. For tests and for sites that build their own.
     */
    public static function setInstance(?self $plugin): void
    {
        self::$instance = $plugin;
    }

    public function register(): void
    {
        add_filter('echo_filter_dup_check', [$this, 'filterDupCheck'], $this->priority(), 1);
        add_filter('echo_skip_seen_is_seen', [$this, 'filterIsSeen'], 10, 2);
        add_filter('echo_skip_seen_counters', [$this, 'filterCounters'], 10, 1);
        add_action('echo_skip_seen_record_url', [$this, 'actionRecordUrl'], 10, 2);

        add_action('wp_after_insert_post', [$this, 'onAfterInsertPost'], 10, 2);
        add_action('added_post_meta', [$this, 'onPostMeta'], 10, 3);
        add_action('updated_post_meta', [$this, 'onPostMeta'], 10, 3);
        add_action('before_delete_post', [$this, 'onBeforeDeletePost'], 10, 1);

        add_action('init', [$this, 'onInit'], 5);
        add_action(Retention::HOOK, [$this, 'onPrune']);
        add_action('wp_initialize_site', [$this, 'onInitializeSite'], 20, 1);
        add_filter('wpmu_drop_tables', [$this, 'filterDropTables'], 10, 2);

        if (function_exists('register_activation_hook') && !$this->isMustUse()) {
            register_activation_hook($this->file, [$this, 'activate']);
            register_deactivation_hook($this->file, [$this, 'deactivate']);
        }

        if (is_admin()) {
            (new AdminPage($this))->register();
            (new SiteHealth($this))->register();
        }

        if (defined('WP_CLI') && constant('WP_CLI') && class_exists('WP_CLI')) {
            \WP_CLI::add_command('skip-seen', new Command($this));
        }
    }

    // Hook callbacks. Each is a thin wrapper so services are built lazily.

    public function filterDupCheck(mixed $value): mixed
    {
        return $this->lookup()->echoFilter($value);
    }

    public function filterIsSeen(mixed $seen, mixed $url = ''): bool
    {
        if ($seen === true) {
            return true;
        }

        return is_string($url) && $this->lookup()->isSeen($url);
    }

    /**
     * @return array<string, mixed>
     */
    public function filterCounters(mixed $value): array
    {
        $base = is_array($value) ? $value : [];

        return array_merge($base, $this->counters()->snapshot());
    }

    public function actionRecordUrl(mixed $url, mixed $postId = 0): void
    {
        if (is_string($url)) {
            $this->recorder()->record($url, is_numeric($postId) ? (int) $postId : 0);
        }
    }

    public function onAfterInsertPost(mixed $postId, mixed $post = null): void
    {
        if (is_numeric($postId)) {
            $this->watcher()->afterInsert((int) $postId, $post);
        }
    }

    public function onPostMeta(mixed $metaId, mixed $postId, mixed $metaKey): void
    {
        $this->watcher()->metaChanged($metaId, $postId, $metaKey);
    }

    public function onBeforeDeletePost(mixed $postId): void
    {
        if (is_numeric($postId)) {
            $this->watcher()->beforeDelete((int) $postId);
        }
    }

    public function onInit(): void
    {
        $this->installer()->onInit();
    }

    public function onPrune(): void
    {
        $this->retention()->prune();
    }

    public function onInitializeSite(mixed $site): void
    {
        $this->installer()->onInitializeSite($site);
    }

    /**
     * @return array<mixed>
     */
    public function filterDropTables(mixed $tables, mixed $siteId = 0): array
    {
        return $this->installer()->dropTables($tables, $siteId);
    }

    public function activate(mixed $networkWide = false): void
    {
        $this->installer()->activate((bool) $networkWide);
    }

    public function deactivate(mixed $networkWide = false): void
    {
        $this->installer()->deactivate((bool) $networkWide);
    }

    // Services.

    public function file(): string
    {
        return $this->file;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function settings(): Settings
    {
        return $this->settings ??= new Settings();
    }

    public function counters(): Counters
    {
        return $this->counters ??= new Counters($this->clock);
    }

    public function log(): Log
    {
        return $this->log ??= new Log($this->settings());
    }

    public function failOpen(): FailOpen
    {
        return $this->failOpen ??= new FailOpen($this->counters(), $this->log());
    }

    public function keys(): KeyFactory
    {
        return $this->keys ??= new KeyFactory(static fn (): Rules => Rules::fromFilters(), $this->keyVersion);
    }

    public function gate(): UrlGate
    {
        return $this->gate ??= new UrlGate($this->keys());
    }

    public function db(): Db
    {
        if ($this->db === null) {
            global $wpdb;
            $this->db = new Db($wpdb);
        }

        return $this->db;
    }

    public function table(): Table
    {
        return $this->table ??= new Table($this->db());
    }

    public function schema(): Schema
    {
        return $this->schema ??= new Schema($this->db(), $this->table(), $this->clock);
    }

    public function versions(): KeyVersions
    {
        return $this->versions ??= new KeyVersions($this->keyVersion, $this->clock);
    }

    public function lookup(): Lookup
    {
        return $this->lookup ??= new Lookup(
            $this->settings(),
            $this->gate(),
            $this->keys(),
            $this->table(),
            $this->schema(),
            $this->versions(),
            $this->counters(),
            $this->failOpen(),
            $this->log(),
            $this->clock
        );
    }

    public function recorder(): Recorder
    {
        return $this->recorder ??= new Recorder(
            $this->settings(),
            $this->gate(),
            $this->keys(),
            $this->table(),
            $this->schema(),
            $this->lookup(),
            $this->counters(),
            $this->failOpen(),
            $this->log(),
            $this->clock
        );
    }

    public function watcher(): PostWatcher
    {
        return $this->watcher ??= new PostWatcher($this->settings(), $this->recorder(), $this->failOpen());
    }

    public function backfill(): Backfill
    {
        return $this->backfill ??= new Backfill(
            $this->db(),
            $this->table(),
            $this->schema(),
            $this->settings(),
            $this->recorder(),
            $this->counters(),
            $this->clock
        );
    }

    public function retention(): Retention
    {
        return $this->retention ??= new Retention(
            $this->table(),
            $this->schema(),
            $this->settings(),
            $this->failOpen(),
            $this->clock
        );
    }

    public function installer(): Installer
    {
        return $this->installer ??= new Installer(
            $this->schema(),
            $this->versions(),
            function_exists('plugin_basename') ? plugin_basename($this->file) : basename($this->file)
        );
    }

    public function isMustUse(): bool
    {
        if (!defined('WPMU_PLUGIN_DIR')) {
            return false;
        }
        $dir = wp_normalize_path((string) constant('WPMU_PLUGIN_DIR'));

        return str_starts_with(wp_normalize_path($this->file), rtrim($dir, '/') . '/');
    }

    private function priority(): int
    {
        if (defined('ECHO_SKIP_SEEN_PRIORITY') && is_numeric(constant('ECHO_SKIP_SEEN_PRIORITY'))) {
            return (int) constant('ECHO_SKIP_SEEN_PRIORITY');
        }

        $priority = apply_filters('echo_skip_seen_priority', self::DEFAULT_PRIORITY);

        return is_numeric($priority) ? (int) $priority : self::DEFAULT_PRIORITY;
    }
}
