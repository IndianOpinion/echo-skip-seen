<?php

/**
 * Plugin Name: Echo Skip Seen (must-use loader)
 * Description: Loads Echo Skip Seen from wp-content/mu-plugins/echo-skip-seen/.
 *
 * Copy this file to wp-content/mu-plugins/echo-skip-seen-loader.php and the
 * plugin folder to wp-content/mu-plugins/echo-skip-seen/. WordPress loads
 * only top-level files in mu-plugins, so this one line does the rest.
 *
 * @package IndianOpinion\EchoSkipSeen
 */

declare(strict_types=1);

require_once __DIR__ . '/echo-skip-seen/echo-skip-seen.php';
