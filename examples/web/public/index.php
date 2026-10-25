<?php

declare(strict_types=1);

/**
 * The web till's front controller. With PHP's built-in server, from examples/:
 *
 *   php -S 127.0.0.1:5080 -t web/public web/public/index.php
 *
 * Under IIS or Apache, point the site at web/public and route every request to
 * this file (see examples/README.md). Listen on loopback only: there is no login.
 */

use AmwalExample\Web\DataPaths;
use AmwalExample\Web\EcrApp;
use AmwalExample\Web\FrontController;

require __DIR__ . '/../../bootstrap.php';

$controller = new FrontController(__DIR__, static fn (): EcrApp => new EcrApp(new DataPaths()));
$raw = (string) file_get_contents('php://input', false, null, 0, FrontController::MAX_BODY_BYTES + 1);
$controller->handle($_SERVER, $_GET, $raw)->send();
