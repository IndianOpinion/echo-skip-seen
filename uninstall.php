<?php

/**
 * Uninstall: drops the table and deletes every option and scheduled event,
 * on every site of a network. Deactivation keeps the data.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!class_exists(\IndianOpinion\EchoSkipSeen\Uninstaller::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\EchoSkipSeen\Uninstaller::run();
