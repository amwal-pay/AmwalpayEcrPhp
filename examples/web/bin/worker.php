<?php

declare(strict_types=1);

/**
 * Runs one transaction (or one receipt fetch) for the web till, detached from
 * the HTTP request that started it. Started by AmwalExample\Web\Spawner:
 *
 *   php web/bin/worker.php <data-dir> <run-id> <generation> run|receipt
 *
 * Not meant to be run by hand.
 */

use AmwalExample\Web\DataPaths;
use AmwalExample\Web\EcrApp;

if (\PHP_SAPI !== 'cli' || $argc !== 5 || !in_array($argv[4], ['run', 'receipt'], true) || preg_match('/^[0-9a-f]{32}$/', $argv[2]) !== 1) {
    fwrite(STDERR, "usage: php web/bin/worker.php <data-dir> <run-id> <generation> run|receipt\n");
    exit(2);
}

require __DIR__ . '/../../bootstrap.php';

// Nothing in this process may stop a payment half way.
set_time_limit(0);
ignore_user_abort(true);

$app = new EcrApp(new DataPaths($argv[1]));
try {
    $app->registry->work($argv[2], (int) $argv[3], $argv[4]);
} catch (\Throwable $e) {
    $app->log->append('App', '[App] worker error: ' . $e->getMessage());
    exit(1);
}
