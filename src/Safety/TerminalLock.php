<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Safety;

/**
 * One financial transaction per terminal at a time, across every PHP process
 * on the machine. Implement it over a shared lock service (Redis, a database
 * row) when several servers drive the same terminal.
 */
interface TerminalLock
{
    /**
     * Takes the terminal without waiting.
     *
     * @return (callable(): void)|null the release function, or null when another caller holds the terminal
     */
    public function tryAcquire(string $terminalKey): ?callable;
}
