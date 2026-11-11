<?php

declare(strict_types=1);

// Minimal WordPress function stubs backed by WpStubs. Only what the plugin calls.
// phpcs:disable PSR1.Files.SideEffects, Squiz.Functions.GlobalFunction

const ARRAY_A = 'ARRAY_A';
const OBJECT = 'OBJECT';

// Hooks.

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStubs::$filters[$hook][$priority][] = [$callback, $args];

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_all_filters(string $hook): void
{
    unset(WpStubs::$filters[$hook]);
}

function has_filter(string $hook, mixed $callback = false): bool|int
{
    if (!isset(WpStubs::$filters[$hook])) {
        return false;
    }
    if ($callback === false) {
        return true;
    }
    foreach (WpStubs::$filters[$hook] as $priority => $callbacks) {
        foreach ($callbacks as [$cb]) {
            if ($cb == $callback) {
                return $priority;
            }
        }
    }

    return false;
}

/**
 * @return int Number of callbacks registered on a hook.
 */
function stub_callback_count(string $hook): int
{
    $count = 0;
    foreach (WpStubs::$filters[$hook] ?? [] as $callbacks) {
        $count += count($callbacks);
    }

    return $count;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if (!isset(WpStubs::$filters[$hook])) {
        return $value;
    }
    $filters = WpStubs::$filters[$hook];
    ksort($filters);
    foreach ($filters as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $accepted));
        }
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    if (!isset(WpStubs::$filters[$hook])) {
        return;
    }
    $filters = WpStubs::$filters[$hook];
    ksort($filters);
    foreach ($filters as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

// Options and transients.

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? [])
        ? WpStubs::$options[WpStubs::$blogId][$name]
        : $default;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string|null $autoload = null): bool
{
    if (array_key_exists($name, WpStubs::$options[WpStubs::$blogId] ?? [])) {
        return false;
    }
    WpStubs::$options[WpStubs::$blogId][$name] = $value;
    WpStubs::$autoload[WpStubs::$blogId][$name] = $autoload === null || $autoload === true || $autoload === 'yes';
    WpStubs::$optionWrites[$name] = (WpStubs::$optionWrites[$name] ?? 0) + 1;

    return true;
}

function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    WpStubs::$options[WpStubs::$blogId][$name] = $value;
    if ($autoload !== null) {
        WpStubs::$autoload[WpStubs::$blogId][$name] = $autoload === true || $autoload === 'yes';
    }
    WpStubs::$optionWrites[$name] = (WpStubs::$optionWrites[$name] ?? 0) + 1;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStubs::$options[WpStubs::$blogId][$name], WpStubs::$autoload[WpStubs::$blogId][$name]);

    return true;
}

function get_transient(string $name): mixed
{
    return get_option('_transient_' . $name, false);
}

function set_transient(string $name, mixed $value, int $expiration = 0): bool
{
    return update_option('_transient_' . $name, $value, false);
}

function delete_transient(string $name): bool
{
    return delete_option('_transient_' . $name);
}

// Posts and meta.

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WpStubs::$posts[WpStubs::$blogId][(int) $post] ?? null;
}

function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
{
    return WpStubs::$meta[WpStubs::$blogId][$postId][$key] ?? ($single ? '' : []);
}

function add_post_meta(int $postId, string $key, mixed $value): int
{
    WpStubs::$meta[WpStubs::$blogId][$postId][$key] = $value;
    do_action('added_post_meta', 1, $postId, $key, $value);

    return 1;
}

function update_post_meta(int $postId, string $key, mixed $value): bool
{
    $exists = isset(WpStubs::$meta[WpStubs::$blogId][$postId][$key]);
    WpStubs::$meta[WpStubs::$blogId][$postId][$key] = $value;
    do_action($exists ? 'updated_post_meta' : 'added_post_meta', 1, $postId, $key, $value);

    return true;
}

function update_meta_cache(string $type, array $ids): bool
{
    return true;
}

function wp_is_post_revision(mixed $post): int|false
{
    $post = get_post($post);

    return $post !== null && $post->post_type === 'revision' ? $post->post_parent : false;
}

function wp_is_post_autosave(mixed $post): int|false
{
    $post = get_post($post);

    return $post !== null && $post->post_type === 'revision' && str_contains($post->post_name, 'autosave')
        ? $post->post_parent
        : false;
}

function wp_trash_post(int $postId): void
{
    $post = WpStubs::$posts[WpStubs::$blogId][$postId];
    $post->post_status = 'trash';
    do_action('wp_after_insert_post', $postId, $post, true, $post);
}

function wp_delete_post(int $postId): void
{
    do_action('before_delete_post', $postId, WpStubs::$posts[WpStubs::$blogId][$postId] ?? null);
    unset(WpStubs::$posts[WpStubs::$blogId][$postId], WpStubs::$meta[WpStubs::$blogId][$postId]);
}

function get_gmt_from_date(string $date): string
{
    return $date;
}

// Multisite.

function is_multisite(): bool
{
    return WpStubs::$multisite;
}

function get_current_blog_id(): int
{
    return WpStubs::$blogId;
}

function switch_to_blog(int $id): bool
{
    global $wpdb;
    WpStubs::$blogStack[] = WpStubs::$blogId;
    WpStubs::$blogId = $id;
    $wpdb->set_blog_id($id);

    return true;
}

function restore_current_blog(): bool
{
    global $wpdb;
    $id = array_pop(WpStubs::$blogStack) ?? 1;
    WpStubs::$blogId = $id;
    $wpdb->set_blog_id($id);

    return true;
}

/**
 * @param array<string, mixed> $args
 * @return list<int>
 */
function get_sites(array $args = []): array
{
    $offset = (int) ($args['offset'] ?? 0);
    $number = (int) ($args['number'] ?? 100);

    return array_slice(WpStubs::$sites, $offset, $number === 0 ? null : $number);
}

function is_plugin_active_for_network(string $plugin): bool
{
    return WpStubs::$networkActive[$plugin] ?? false;
}

// Cron.

function wp_next_scheduled(string $hook): int|false
{
    return WpStubs::$cron[WpStubs::$blogId][$hook] ?? false;
}

function wp_schedule_event(int $timestamp, string $recurrence, string $hook): bool
{
    WpStubs::$cron[WpStubs::$blogId][$hook] = $timestamp;

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WpStubs::$cron[WpStubs::$blogId][$hook]);
    unset(WpStubs::$cron[WpStubs::$blogId][$hook]);

    return $had ? 1 : 0;
}

// Plugin and environment.

function is_admin(): bool
{
    return WpStubs::$isAdmin;
}

function home_url(string $path = ''): string
{
    return rtrim(WpStubs::$homeUrl, '/') . '/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string
{
    return 'https://www.mysite.test/wp-admin/' . ltrim($path, '/');
}

function plugins_url(string $path = '', string $plugin = ''): string
{
    return 'https://www.mysite.test/wp-content/plugins/echo-skip-seen/' . ltrim($path, '/');
}

function plugin_basename(string $file): string
{
    return 'echo-skip-seen/' . basename($file);
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

function register_activation_hook(string $file, callable $callback): void
{
    add_action('activate_' . plugin_basename($file), $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    add_action('deactivate_' . plugin_basename($file), $callback);
}

function dbDelta(string|array $queries): array
{
    global $wpdb;
    $sql = is_array($queries) ? implode(';', $queries) : $queries;
    if (preg_match('/CREATE TABLE ([A-Za-z0-9_]+)/', $sql, $m) && !isset($wpdb->tables[$m[1]])) {
        $wpdb->query($sql);
    }

    // Like the real dbDelta in the production trap: an existing table is
    // left as it is, and a missing column is not reported.
    return [];
}

// Escaping, i18n, formatting.

function __(string $text, string $domain = 'default'): string
{
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html($text);
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_textarea(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function number_format_i18n(float|int $number, int $decimals = 0): string
{
    return number_format($number, $decimals);
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function sanitize_text_field(string $text): string
{
    return trim(strip_tags($text));
}

function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}

function wp_json_encode(mixed $value): string|false
{
    return json_encode($value);
}

/**
 * @param array<string, string> $args
 */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

// Users, nonces, responses.

function current_user_can(string $capability): bool
{
    return WpStubs::$caps[$capability] ?? false;
}

function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action;
}

function wp_verify_nonce(string $nonce, string $action = ''): int|false
{
    return $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce'): string
{
    $field = '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">';
    echo $field;

    return $field;
}

function check_admin_referer(string $action, string $name = '_wpnonce'): int
{
    $nonce = $_REQUEST[$name] ?? ($_POST[$name] ?? '');
    if (!is_string($nonce) || wp_verify_nonce($nonce, $action) === false) {
        throw new WpDieException('The link you followed has expired.');
    }

    return 1;
}

function check_ajax_referer(string $action, string $arg = '_ajax_nonce', bool $die = true): int|false
{
    $nonce = $_REQUEST[$arg] ?? ($_POST[$arg] ?? '');
    $ok = is_string($nonce) ? wp_verify_nonce($nonce, $action) : false;
    if ($ok === false && $die) {
        throw new WpDieException('-1');
    }

    return $ok;
}

function wp_die(string $message = '', string $title = '', array $args = []): never
{
    throw new WpDieException($message);
}

function wp_send_json_success(mixed $data = null, ?int $status = null): never
{
    throw new WpJsonResponse(true, $data, $status);
}

function wp_send_json_error(mixed $data = null, ?int $status = null): never
{
    throw new WpJsonResponse(false, $data, $status);
}

function wp_safe_redirect(string $location): bool
{
    WpStubs::$redirect = $location;

    return true;
}

function add_management_page(string $page, string $menu, string $cap, string $slug, callable $callback): string
{
    return 'tools_page_' . $slug;
}

function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $footer = false): void
{
}

function wp_localize_script(string $handle, string $name, array $data): bool
{
    return true;
}
