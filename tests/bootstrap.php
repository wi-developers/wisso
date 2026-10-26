<?php

declare(strict_types=1);

// Runs inside the rig container, as the web server user, against the real instance (same database, Redis and
// config as the Apache that answers the HTTP calls). No test double of Nextcloud is used.
define('OC_CONSOLE', 1);
require_once '/var/www/html/lib/base.php';
\OC_App::loadApp('wisso');

require_once __DIR__ . '/WissoTestCase.php';
