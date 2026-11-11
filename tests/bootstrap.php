<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects

define('ABSPATH', __DIR__ . '/fake-wordpress/');

require_once __DIR__ . '/stubs/FakeWpdb.php';
require_once __DIR__ . '/stubs/WpStubs.php';
require_once __DIR__ . '/stubs/classes.php';
require_once __DIR__ . '/stubs/cli-utils.php';
require_once __DIR__ . '/stubs/functions.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

WpStubs::reset();
