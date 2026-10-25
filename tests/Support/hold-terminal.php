<?php

declare(strict_types=1);

/**
 * Another PHP process running a long Wi-Fi sale: takes the terminal through the
 * SDK's own lock, says so, and holds it until killed.
 *
 *   php tests/Support/hold-terminal.php <state-dir> <terminal-key>
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$lock = new AmwalPay\Ecr\Safety\FileTerminalLock($argv[1]);
$release = $lock->tryAcquire($argv[2]);
if ($release === null) {
    echo "BUSY\n";
    exit(1);
}
echo "LISTENING locked:1\n";
flush();
sleep(60);
$release();
