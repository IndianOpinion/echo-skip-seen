<?php

declare(strict_types=1);

namespace IndianOpinion\EchoSkipSeen\Admin;

use Closure;
use IndianOpinion\EchoSkipSeen\Backfill;
use IndianOpinion\EchoSkipSeen\BackfillRequest;
use IndianOpinion\EchoSkipSeen\BackfillResult;
use IndianOpinion\EchoSkipSeen\Db\Schema;
use IndianOpinion\EchoSkipSeen\Plugin;
use IndianOpinion\EchoSkipSeen\Settings;

/**
 * The Tools page: status, counters, settings, backfill and a URL checker.
 *
 * Every handler checks a nonce and the manage_options capability before
 * doing anything. Every output is escaped.
 *
 * @phpstan-import-type Snapshot from \IndianOpinion\EchoSkipSeen\Counters
 */
final class AdminPage
{
    public const SLUG = 'echo-skip-seen';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE = 'echo_skip_seen_save';
    public const ACTION_RESET = 'echo_skip_seen_reset';
    public const ACTION_BACKFILL = 'echo_skip_seen_backfill';
    public const NONCE_CHECK = 'echo_skip_seen_check';

    private Closure $terminate;

    /**
     * @param (Closure(): void)|null $terminate Ends the request after a redirect. Tests pass a no-op.
     */
    public function __construct(private readonly Plugin $plugin, ?Closure $terminate = null)
    {
        $this->terminate = $terminate ?? static function (): void {
            exit;
        };
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_RESET, [$this, 'handleReset']);
        add_action('wp_ajax_' . self::ACTION_BACKFILL, [$this, 'handleBackfill']);
        add_action('admin_notices', [$this, 'schemaNotice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Echo Skip Seen', 'echo-skip-seen'),
            __('Echo Skip Seen', 'echo-skip-seen'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(mixed $hook = ''): void
    {
        if ($hook !== 'tools_page_' . self::SLUG) {
            return;
        }

        $url = plugins_url('assets/admin.js', $this->plugin->file());
        wp_enqueue_script('echo-skip-seen-admin', $url, [], Plugin::VERSION, true);
        wp_localize_script('echo-skip-seen-admin', 'echoSkipSeen', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::ACTION_BACKFILL,
            'nonce' => wp_create_nonce(self::ACTION_BACKFILL),
            'running' => __('Running batch', 'echo-skip-seen'),
            'done' => __('Backfill complete.', 'echo-skip-seen'),
            'failed' => __('Backfill stopped:', 'echo-skip-seen'),
        ]);
    }

    public function schemaNotice(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }

        $check = $this->plugin->schema()->lastCheck();
        if ($check === null || $check['ok']) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__('Echo Skip Seen: the table check failed, so every lookup passes through. Missing:', 'echo-skip-seen')
            . ' ' . esc_html(implode(', ', $check['missing']))
            . '. ' . esc_html__('Run "wp skip-seen verify-schema" or check the database user can ALTER tables.', 'echo-skip-seen')
            . '</p></div>';
    }

    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'echo-skip-seen'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION_SAVE);

        $post = wp_unslash($_POST);
        $post = is_array($post) ? $post : [];

        $this->plugin->settings()->save([
            'enabled' => isset($post['enabled']) ? '1' : '0',
            'debug' => isset($post['debug']) ? '1' : '0',
            'retention_days' => is_string($post['retention_days'] ?? null) ? $post['retention_days'] : '',
            'meta_keys' => is_string($post['meta_keys'] ?? null) ? $post['meta_keys'] : '',
        ]);

        $this->redirect('saved');
    }

    public function handleReset(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to do that.', 'echo-skip-seen'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION_RESET);

        $this->plugin->counters()->reset();
        $this->redirect('reset');
    }

    /**
     * Runs one backfill batch from the stored cursor and returns the running totals.
     */
    public function handleBackfill(): void
    {
        if (!check_ajax_referer(self::ACTION_BACKFILL, 'nonce', false)) {
            wp_send_json_error(['message' => __('Invalid or expired nonce. Reload the page.', 'echo-skip-seen')], 403);
        }
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('You are not allowed to do that.', 'echo-skip-seen')], 403);
        }

        $restart = isset($_POST['restart']) && $_POST['restart'] === '1';
        $cursor = get_option(Backfill::CURSOR_OPTION, []);
        $total = ($restart || !is_array($cursor)) ? new BackfillResult() : BackfillResult::fromArray($cursor);
        if ($total->done) {
            $total = new BackfillResult();
        }

        try {
            $batch = $this->plugin->backfill()->batch(new BackfillRequest($total->lastId));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        $total->add($batch);
        if (get_option(Backfill::CURSOR_OPTION, null) === null) {
            add_option(Backfill::CURSOR_OPTION, $total->toArray(), '', false);
        } else {
            update_option(Backfill::CURSOR_OPTION, $total->toArray(), false);
        }
        $this->plugin->counters()->flush();

        wp_send_json_success($total->toArray());
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to see this page.', 'echo-skip-seen'), '', ['response' => 403]);
        }

        $settings = $this->plugin->settings();
        $schema = $this->plugin->schema();
        $counters = $this->plugin->counters()->snapshot();
        $all = $settings->all();
        $metaKeys = $settings->metaKeys();

        echo '<div class="wrap"><h1>' . esc_html__('Echo Skip Seen', 'echo-skip-seen') . '</h1>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag set by our own redirect; only compared to fixed words.
        $notice = isset($_GET['echo-skip-seen']) && is_string($_GET['echo-skip-seen']) ? sanitize_key($_GET['echo-skip-seen']) : '';
        if ($notice === 'saved') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'echo-skip-seen') . '</p></div>';
        } elseif ($notice === 'reset') {
            echo '<div class="notice notice-success"><p>' . esc_html__('Counters reset.', 'echo-skip-seen') . '</p></div>';
        }

        $this->renderStatus($settings->enabled(), $schema, $settings->retentionDays());
        $this->renderCounters($counters);
        $this->renderSettings($all, $metaKeys);
        $this->renderBackfill($metaKeys);
        $this->renderCheck();

        echo '</div>';
    }

    private function renderStatus(bool $enabled, Schema $schema, int $retention): void
    {
        $ready = $schema->isReady();
        $check = $schema->lastCheck();
        $rows = null;
        $range = ['oldest' => null, 'newest' => null];
        if ($ready) {
            try {
                $rows = $this->plugin->table()->count();
                $range = $this->plugin->table()->lastSeenRange();
            } catch (\Throwable) {
                $rows = null;
            }
        }

        $schemaText = $ready
            ? __('ok', 'echo-skip-seen')
            : __('not ready', 'echo-skip-seen') . ($check !== null && $check['missing'] !== [] ? ': ' . implode(', ', $check['missing']) : '');

        echo '<h2>' . esc_html__('Status', 'echo-skip-seen') . '</h2><table class="widefat striped" style="max-width:48em"><tbody>';
        $this->row(__('Enabled', 'echo-skip-seen'), $enabled ? __('yes', 'echo-skip-seen') : __('no', 'echo-skip-seen'));
        $this->row(__('Schema', 'echo-skip-seen'), $schemaText);
        $this->row(__('Rows', 'echo-skip-seen'), $rows === null ? __('unknown', 'echo-skip-seen') : number_format_i18n($rows));
        $this->row(__('Oldest last seen (UTC)', 'echo-skip-seen'), self::date($range['oldest']));
        $this->row(__('Newest last seen (UTC)', 'echo-skip-seen'), self::date($range['newest']));
        $this->row(
            __('Retention', 'echo-skip-seen'),
            $retention === 0
                ? __('keep forever', 'echo-skip-seen')
                /* translators: %d: number of days. */
                : sprintf(__('%d days', 'echo-skip-seen'), $retention)
        );
        echo '</tbody></table>';
    }

    /**
     * @param Snapshot $counters
     */
    private function renderCounters(array $counters): void
    {
        echo '<h2>' . esc_html__('Counters', 'echo-skip-seen') . '</h2><table class="widefat striped" style="max-width:48em"><tbody>';
        foreach (['checked', 'skipped', 'recorded', 'failed_open'] as $name) {
            $since = $counters['since'][$name] ?? null;
            $this->row(
                str_replace('_', ' ', $name),
                number_format_i18n($counters[$name]) . ' (' . __('since', 'echo-skip-seen') . ' ' . self::date($since) . ')'
            );
        }
        $rate = $counters['skip_rate'];
        $this->row(__('Skip rate', 'echo-skip-seen'), $rate === null ? '-' : number_format_i18n($rate * 100, 1) . '%');
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESET) . '">';
        wp_nonce_field(self::ACTION_RESET);
        echo '<p><button type="submit" class="button">' . esc_html__('Reset counters', 'echo-skip-seen') . '</button></p></form>';
    }

    /**
     * @param array{enabled: bool, retention_days: int, meta_keys: list<string>, debug: bool} $all
     * @param list<string> $metaKeys
     */
    private function renderSettings(array $all, array $metaKeys): void
    {
        echo '<h2>' . esc_html__('Settings', 'echo-skip-seen') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        wp_nonce_field(self::ACTION_SAVE);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Enabled', 'echo-skip-seen') . '</th><td><label><input type="checkbox" name="enabled" value="1"'
            . ($all['enabled'] ? ' checked' : '') . '> ' . esc_html__('Answer the duplicate filter', 'echo-skip-seen') . '</label></td></tr>';

        echo '<tr><th scope="row"><label for="ess-retention">' . esc_html__('Retention (days)', 'echo-skip-seen') . '</label></th><td>'
            . '<input id="ess-retention" type="number" min="0" max="' . esc_attr((string) Settings::MAX_RETENTION_DAYS) . '" name="retention_days" value="'
            . esc_attr((string) $all['retention_days']) . '" class="small-text"> '
            . esc_html__('0 keeps rows forever.', 'echo-skip-seen') . '</td></tr>';

        echo '<tr><th scope="row"><label for="ess-meta">' . esc_html__('Source URL meta keys', 'echo-skip-seen') . '</label></th><td>'
            . '<textarea id="ess-meta" name="meta_keys" rows="3" cols="40">' . esc_textarea(implode("\n", $all['meta_keys'])) . '</textarea>'
            . '<p class="description">' . esc_html__('One per line, in order. The first key whose value is an http or https URL wins. To find the key, open one imported post, show Custom Fields, and look for the field that holds the original article URL.', 'echo-skip-seen') . '</p>';
        if ($metaKeys === []) {
            echo '<p class="description"><strong>' . esc_html__('No meta key is configured, so posts are not recorded automatically. Only the record function and the record action add rows.', 'echo-skip-seen') . '</strong></p>';
        } elseif ($metaKeys !== $all['meta_keys']) {
            echo '<p class="description">' . esc_html__('In effect after filters:', 'echo-skip-seen') . ' <code>' . esc_html(implode(', ', $metaKeys)) . '</code></p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Debug logging', 'echo-skip-seen') . '</th><td><label><input type="checkbox" name="debug" value="1"'
            . ($all['debug'] ? ' checked' : '') . '> ' . esc_html__('Write one PHP error log line per skip and per failure', 'echo-skip-seen') . '</label></td></tr>';

        echo '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__('Save settings', 'echo-skip-seen') . '</button></p></form>';
    }

    /**
     * @param list<string> $metaKeys
     */
    private function renderBackfill(array $metaKeys): void
    {
        $cursor = get_option(Backfill::CURSOR_OPTION, []);
        $cursor = BackfillResult::fromArray(is_array($cursor) ? $cursor : []);

        echo '<h2>' . esc_html__('Backfill', 'echo-skip-seen') . '</h2>';
        echo '<p>' . esc_html__('Seeds the table from existing posts, one batch per request. Closing the page stops it; pressing the button again resumes.', 'echo-skip-seen') . '</p>';
        echo '<p><button type="button" class="button" id="ess-backfill"' . ($metaKeys === [] ? ' disabled' : '') . '>'
            . esc_html($cursor->lastId > 0 && !$cursor->done ? __('Resume backfill', 'echo-skip-seen') : __('Run backfill', 'echo-skip-seen'))
            . '</button> <button type="button" class="button-link" id="ess-backfill-restart"' . ($metaKeys === [] ? ' disabled' : '') . '>'
            . esc_html__('Start again from the first post', 'echo-skip-seen') . '</button></p>';
        echo '<p id="ess-backfill-status" aria-live="polite">';
        if ($cursor->lastId > 0) {
            echo esc_html(self::totals($cursor));
        }
        echo '</p>';
    }

    private function renderCheck(): void
    {
        echo '<h2>' . esc_html__('Check a URL', 'echo-skip-seen') . '</h2>';
        echo '<form method="post"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        wp_nonce_field(self::NONCE_CHECK, 'echo_skip_seen_check_nonce');

        $url = '';
        $nonce = isset($_POST['echo_skip_seen_check_nonce']) && is_string($_POST['echo_skip_seen_check_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['echo_skip_seen_check_nonce']))
            : '';
        if ($nonce !== '' && wp_verify_nonce($nonce, self::NONCE_CHECK) !== false && isset($_POST['echo_skip_seen_check_url']) && is_string($_POST['echo_skip_seen_check_url'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- kept byte for byte so the keys match what the importer sends; it is only keyed and printed escaped.
            $url = trim((string) wp_unslash($_POST['echo_skip_seen_check_url']));
        }

        echo '<p><input type="url" name="echo_skip_seen_check_url" class="regular-text" value="' . esc_attr($url) . '"> '
            . '<button type="submit" class="button">' . esc_html__('Check', 'echo-skip-seen') . '</button></p></form>';

        if ($url === '') {
            return;
        }

        $report = (new UrlReport($this->plugin))->build($url);
        if ($report['keys'] === null) {
            echo '<p>' . esc_html($report['note']) . '</p>';

            return;
        }

        echo '<table class="widefat striped" style="max-width:64em"><tbody>';
        $this->row(__('Canonical key', 'echo-skip-seen'), $report['keys']['canonical']);
        $this->row(__('Canonical hash', 'echo-skip-seen'), $report['keys']['canonical_hash'] . ' (' . $report['canonical_present'] . ')');
        $this->row(__('Plain key', 'echo-skip-seen'), $report['keys']['plain']);
        $this->row(__('Plain hash', 'echo-skip-seen'), $report['keys']['plain_hash'] . ' (' . $report['plain_present'] . ')');
        $this->row(__('A lookup would skip it', 'echo-skip-seen'), $report['seen'] ? __('yes', 'echo-skip-seen') : __('no', 'echo-skip-seen'));
        echo '</tbody></table>';
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th scope="row" style="width:16em">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }

    private function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'echo-skip-seen' => $notice],
            admin_url('tools.php')
        ));
        ($this->terminate)();
    }

    private static function date(?int $time): string
    {
        return $time === null || $time <= 0 ? '-' : gmdate('Y-m-d H:i', $time);
    }

    private static function totals(BackfillResult $result): string
    {
        return sprintf(
            /* translators: 1: last post id, 2: scanned, 3: with URL, 4: written, 5: present, 6: rejected, 7: skipped as expired. */
            __('Up to post %1$d: %2$d scanned, %3$d with a source URL, %4$d rows written, %5$d already present, %6$d rejected, %7$d older than retention.', 'echo-skip-seen'),
            $result->lastId,
            $result->scanned,
            $result->withUrl,
            $result->written,
            $result->present,
            $result->rejected,
            $result->expired
        ) . ($result->done ? ' ' . __('Done.', 'echo-skip-seen') : '');
    }
}
