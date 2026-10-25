<?php

declare(strict_types=1);

namespace AmwalExample\Web;

use AmwalExample\Core\EcrExampleSettings;
use AmwalExample\Core\LogBuffer;
use AmwalExample\Core\SecretProtector;
use AmwalExample\Core\TerminalRepository;
use AmwalExample\Core\TransactionEngine;

/**
 * The composition root: stores, the log buffer the SDK writes to, the engine
 * and the registry. Built once per request, and once per worker process.
 */
final class EcrApp
{
    public readonly LogBuffer $log;
    public readonly TerminalRepository $terminals;
    public readonly EcrExampleSettings $settings;
    public readonly TransactionEngine $engine;
    public readonly TransactionRegistry $registry;

    public function __construct(public readonly DataPaths $paths)
    {
        $paths->ensure();
        $this->log = new LogBuffer($paths->log());
        $this->terminals = new TerminalRepository($paths->terminals());
        $this->settings = new EcrExampleSettings($paths->settings(), new SecretProtector($paths->keys()));
        $this->engine = new TransactionEngine($this->terminals, $this->settings, $this->log, $paths->sdkState());
        $this->registry = new TransactionRegistry($paths->runs(), $this->engine, new Spawner($paths));
    }
}
