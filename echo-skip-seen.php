<?php

/**
 * Plugin Name:       Echo Skip Seen
 * Plugin URI:        https://github.com/IndianOpinion/echo-skip-seen
 * Description:       Skips feed items whose article the site has already imported, before the importer downloads the page.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       echo-skip-seen
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    return;
}

if (!class_exists(\IndianOpinion\EchoSkipSeen\Plugin::class)) {
    require_once __DIR__ . '/src/autoload.php';
}

\IndianOpinion\EchoSkipSeen\Plugin::boot(__FILE__);
