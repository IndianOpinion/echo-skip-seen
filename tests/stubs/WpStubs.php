<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace

/**
 * State behind the WordPress function stubs. Reset before every test.
 */
final class WpStubs
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $filters = [];

    /** @var array<int, array<string, mixed>> Blog id to options. */
    public static array $options = [];

    /** @var array<int, array<string, bool>> Blog id to option autoload flags. */
    public static array $autoload = [];

    /** @var array<string, int> Option name to number of writes. */
    public static array $optionWrites = [];

    /** @var array<int, array<int, WP_Post>> */
    public static array $posts = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    public static array $meta = [];

    /** @var array<int, array<string, int>> */
    public static array $cron = [];

    /** @var array<string, bool> */
    public static array $caps = ['manage_options' => true];

    public static bool $multisite = false;

    /** @var list<int> */
    public static array $sites = [1];

    public static int $blogId = 1;

    /** @var list<int> */
    public static array $blogStack = [];

    public static bool $isAdmin = false;

    public static string $homeUrl = 'https://www.mysite.test/';

    public static ?string $redirect = null;

    /** @var list<string> */
    public static array $cliOutput = [];

    /** @var array<string, object> */
    public static array $cliCommands = [];

    /** @var array<string, bool> Plugins active for the network. */
    public static array $networkActive = [];

    public static int $nextPostId = 1;

    public static int $objectCacheClears = 0;

    public static function reset(): void
    {
        global $wpdb;
        self::$filters = [];
        self::$options = [];
        self::$autoload = [];
        self::$optionWrites = [];
        self::$posts = [];
        self::$meta = [];
        self::$cron = [];
        self::$caps = ['manage_options' => true];
        self::$multisite = false;
        self::$sites = [1];
        self::$blogId = 1;
        self::$blogStack = [];
        self::$isAdmin = false;
        self::$homeUrl = 'https://www.mysite.test/';
        self::$redirect = null;
        self::$cliOutput = [];
        self::$cliCommands = [];
        self::$networkActive = [];
        self::$nextPostId = 1;
        self::$objectCacheClears = 0;
        $wpdb = new wpdb();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
    }

    /**
     * @return array<int, WP_Post>
     */
    public static function posts(): array
    {
        return self::$posts[self::$blogId] ?? [];
    }

    /**
     * Simulates wp_insert_post(): stores the post, writes meta (before or
     * after the insert action, as importers do), fires the hooks.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $meta
     */
    public static function insertPost(array $fields = [], array $meta = [], bool $metaFirst = true): WP_Post
    {
        $id = self::$nextPostId++;
        $date = $fields['post_date_gmt'] ?? gmdate('Y-m-d H:i:s');
        $post = new WP_Post((object) array_merge([
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_date' => $date,
            'post_date_gmt' => $date,
            'post_parent' => 0,
            'post_name' => 'post-' . $id,
        ], $fields));
        self::$posts[self::$blogId][$id] = $post;

        if ($metaFirst) {
            foreach ($meta as $key => $value) {
                add_post_meta($id, $key, $value);
            }
        }
        do_action('wp_after_insert_post', $id, $post, false, null);
        if (!$metaFirst) {
            foreach ($meta as $key => $value) {
                add_post_meta($id, $key, $value);
            }
        }

        return $post;
    }

    /**
     * Adds posts without firing any hook (fixtures for backfill).
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $meta
     */
    public static function addPostQuietly(array $fields = [], array $meta = []): WP_Post
    {
        $id = self::$nextPostId++;
        $date = $fields['post_date_gmt'] ?? gmdate('Y-m-d H:i:s');
        $post = new WP_Post((object) array_merge([
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_date' => $date,
            'post_date_gmt' => $date,
            'post_parent' => 0,
            'post_name' => 'post-' . $id,
        ], $fields));
        self::$posts[self::$blogId][$id] = $post;
        foreach ($meta as $key => $value) {
            self::$meta[self::$blogId][$id][$key] = $value;
        }

        return $post;
    }

    public static function writes(string $option): int
    {
        return self::$optionWrites[$option] ?? 0;
    }
}

/**
 * Thrown by the wp_die() stub.
 */
final class WpDieException extends RuntimeException
{
}

/**
 * Thrown by the wp_send_json_*() stubs.
 */
final class WpJsonResponse extends RuntimeException
{
    /**
     * @param mixed $data
     */
    public function __construct(public readonly bool $success, public readonly mixed $data, public readonly ?int $status)
    {
        parent::__construct('json response');
    }
}

/**
 * Thrown by WP_CLI::error().
 */
final class WpCliError extends RuntimeException
{
}
