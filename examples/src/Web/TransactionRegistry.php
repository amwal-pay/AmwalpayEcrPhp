<?php

declare(strict_types=1);

namespace AmwalExample\Web;

use AmwalExample\Core\StateChecking;
use AmwalExample\Core\StateCompleted;
use AmwalExample\Core\StateFailed;
use AmwalExample\Core\StateIdle;
use AmwalExample\Core\StateInProgress;
use AmwalExample\Core\StateInquired;
use AmwalExample\Core\TransactionEngine;
use AmwalExample\Core\TransactionOutcomes;
use AmwalExample\Core\TransactionRequest;
use AmwalExample\Core\TransactionState;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Result\ReceiptUnavailable;

/**
 * Runs transactions on the server, keyed by an id the browser polls. The PHP
 * port of the web sample's TransactionRegistry.
 *
 * **A payment is not tied to the HTTP request.** Each run executes in its own
 * worker process ({@see Spawner}); nothing looks at the request's lifetime. The
 * only way to stop waiting is the explicit Cancel, which cannot un-send a
 * request and shows an unknown outcome, never "cancelled".
 *
 * **One running transaction per terminal serial**, enforced here and not by
 * disabling a button: a double click, a second tab or a repeated POST must not
 * put two sales on one terminal. (The SDK's own guard enforces the same rule a
 * level lower.)
 *
 * PHP serves each request in its own process, so the runs live in files under
 * one lock instead of a dictionary under `lock (_gate)`. A worker holds a lock
 * of its own while it runs; a busy run whose worker lock is free belongs to a
 * worker that died, and becomes a failure carrying its reference rather than
 * spinning forever.
 */
final class TransactionRegistry
{
    /** How long a finished run nobody acknowledged is kept: an unknown outcome must survive a night away. */
    public const FINISHED_RETENTION_SECONDS = 12 * 3600;

    /** A worker takes its lock within milliseconds of starting; this is the grace before calling it lost. */
    private const WORKER_START_GRACE_SECONDS = 15;

    private const ALLOWED_CLASSES = [
        \AmwalExample\Core\TransactionRequest::class,
        \AmwalExample\Core\StateIdle::class, \AmwalExample\Core\StateChecking::class, \AmwalExample\Core\StateInProgress::class,
        \AmwalExample\Core\StateCompleted::class, \AmwalExample\Core\StateInquired::class, \AmwalExample\Core\StateFailed::class,
        \AmwalPay\Ecr\EcrTransactionType::class, \AmwalPay\Ecr\Result\NextStep::class,
        \AmwalPay\Ecr\Result\Approved::class, \AmwalPay\Ecr\Result\Declined::class, \AmwalPay\Ecr\Result\Failed::class,
        \AmwalPay\Ecr\Result\Found::class, \AmwalPay\Ecr\Result\NotFound::class, \AmwalPay\Ecr\Result\InquiryFailed::class,
        \AmwalPay\Ecr\Result\EcrTransaction::class,
        \AmwalPay\Ecr\Result\ReceiptReady::class, \AmwalPay\Ecr\Result\ReceiptUnavailable::class, \AmwalPay\Ecr\Result\ReceiptFailed::class,
        \AmwalPay\Ecr\Failure\Unreachable::class, \AmwalPay\Ecr\Failure\Timeout::class, \AmwalPay\Ecr\Failure\Malformed::class,
        \AmwalPay\Ecr\Failure\ConnectionLost::class, \AmwalPay\Ecr\Failure\Unauthenticated::class, \AmwalPay\Ecr\Failure\Busy::class,
        \AmwalPay\Ecr\Failure\Unresolved::class, \AmwalPay\Ecr\Failure\Invalid::class,
    ];

    /** @var (callable(): float)|null */
    private $clock;

    public function __construct(
        private readonly string $directory,
        private readonly TransactionEngine $engine,
        private readonly Spawner $spawner,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @return array{0: 'started', 1: TransactionSnapshot}|array{0: 'busy', 1: string}
     */
    public function start(TransactionRequest $request, bool $usesWebService, int $responseTimeoutSeconds = 120): array
    {
        return $this->locked(function () use ($request, $usesWebService, $responseTimeoutSeconds): array {
            $this->sweep();
            $existing = $this->runningOn($request->terminalSerial);
            if ($existing !== null) {
                return ['busy', $existing];
            }
            // The reference is made and stored before anything is sent, so it is
            // known even if this process, or the worker, dies mid-flight.
            if ($request->type->movesMoney() && $request->merchantReference === '') {
                $request = $request->withMerchantReference(TransactionEngine::newReference());
            }
            $now = $this->now();
            $run = [
                'id' => bin2hex(random_bytes(16)),
                'terminalSerial' => $request->terminalSerial,
                'usesWebService' => $usesWebService,
                'timeoutSeconds' => max(1, $responseTimeoutSeconds),
                'started' => $now,
                'updated' => $now,
                'busySince' => $now,
                'version' => 0,
                'generation' => 0,
                'spawnedAt' => $now,
                'request' => $request,
                'state' => new StateChecking(),
            ];
            $run = $this->begin($run, $request);
            return ['started', $this->snapshot($run)];
        });
    }

    public function find(string $id): ?TransactionSnapshot
    {
        return $this->locked(function () use ($id): ?TransactionSnapshot {
            $this->sweep();
            $run = $this->read($id);
            return $run === null ? null : $this->snapshot($run);
        });
    }

    /** @return list<TransactionSnapshot> runs the operator has not acknowledged, newest first */
    public function pending(): array
    {
        return $this->locked(function (): array {
            $this->sweep();
            $runs = $this->all();
            usort($runs, static fn (array $a, array $b): int => $b['started'] <=> $a['started']);
            return array_map(fn (array $run): TransactionSnapshot => $this->snapshot($run), $runs);
        });
    }

    /** Stops waiting, like the WPF Cancel button. A no-op once the run has finished. */
    public function cancel(string $id): ?TransactionSnapshot
    {
        return $this->locked(function () use ($id): ?TransactionSnapshot {
            $run = $this->read($id);
            if ($run === null) {
                return null;
            }
            $state = $run['state'];
            if ($state instanceof StateChecking) {
                // Nothing has been sent: the worker asks before sending and will be told no.
                $run = $this->update($run, new StateIdle());
            } elseif ($state instanceof StateInProgress) {
                // Already sent: stopping here does not stop the terminal.
                $run = $this->update($run, TransactionOutcomes::cancelledState($run['request']));
            }
            return $this->snapshot($run);
        });
    }

    /**
     * After an unknown outcome: look the transaction up by the reference it was
     * sent with, on the same run id. The only follow-up offered: never a retry.
     *
     * @return array{0: 'started', 1: TransactionSnapshot}|array{0: 'notFound'|'notApplicable'}|array{0: 'busy', 1: string}
     */
    public function inquire(string $id): array
    {
        return $this->locked(function () use ($id): array {
            $this->sweep();
            $run = $this->read($id);
            if ($run === null) {
                return ['notFound'];
            }
            $state = $run['state'];
            if (!$state instanceof StateFailed || $state->inquirableReference === null) {
                return ['notApplicable'];
            }
            $existing = $this->runningOn($run['terminalSerial']);
            if ($existing !== null) {
                return ['busy', $existing];
            }
            $inquiry = TransactionRequest::inquiryByReference($run['terminalSerial'], $state->inquirableReference);
            $run['request'] = $inquiry;
            $run = $this->begin($run, $inquiry);
            return ['started', $this->snapshot($run)];
        });
    }

    /**
     * Fetches the e-receipt of an inquired transaction (Wi-Fi), in a worker.
     *
     * @return array{0: 'started', 1: TransactionSnapshot}|array{0: 'notFound'|'notApplicable'}
     */
    public function requestReceipt(string $id): array
    {
        return $this->locked(function () use ($id): array {
            $run = $this->read($id);
            if ($run === null) {
                return ['notFound'];
            }
            $state = $run['state'];
            if (!$state instanceof StateInquired || $state->receipt !== null || $state->fetchingReceipt || $run['usesWebService']) {
                return ['notApplicable'];
            }
            $run['busySince'] = $this->now();
            $run['generation']++;
            $run['spawnedAt'] = $this->now();
            $run = $this->update($run, $state->withReceipt(null, true));
            $this->spawner->start($run['id'], $run['generation'], 'receipt');
            return ['started', $this->snapshot($run)];
        });
    }

    /**
     * Asks the terminal to put its receipt away. Does not change the run's
     * state: the result dialog stays open.
     *
     * @return array{0: 'done', 1: TransactionSnapshot, 2: string}|array{0: 'notFound'}|array{0: 'notApplicable', 1: ?string}
     */
    public function closeReceipt(string $id): array
    {
        $prepared = $this->locked(function () use ($id): array {
            $run = $this->read($id);
            if ($run === null) {
                return ['notFound'];
            }
            $state = $run['state'];
            if (!$state instanceof StateCompleted) {
                return ['notApplicable', null];
            }
            $original = '';
            if ($run['usesWebService'] && $state->type->movesMoney()) {
                $original = $state->result->merchantReference;
            }
            if ($run['usesWebService'] && trim($original) === '') {
                return ['notApplicable', 'Close receipt needs the sale / void / refund reference'];
            }
            return ['ready', $this->snapshot($run), $run['terminalSerial'], $original];
        });
        if ($prepared[0] !== 'ready') {
            return $prepared;
        }
        $message = $this->engine->closeTerminalReceipt($prepared[2], $prepared[3]);
        return ['done', $prepared[1], $message];
    }

    /** The operator has read the result. A running transaction cannot be dismissed: false. */
    public function acknowledge(string $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $run = $this->read($id);
            if ($run === null) {
                return true;
            }
            if ($run['state']->isBusy()) {
                return false;
            }
            $this->remove($id);
            return true;
        });
    }

    // ── The worker's side ─────────────────────────────────────────────

    /**
     * Executes the run in this (worker) process. Holds the generation's worker
     * lock throughout, so the registry can tell a live worker from a dead one.
     */
    public function work(string $id, int $generation, string $mode): void
    {
        $lock = $this->workerLock($id, $generation);
        try {
            $run = $this->locked(fn (): ?array => $this->read($id));
            if ($run === null || $run['generation'] !== $generation) {
                return;
            }
            if ($mode === 'receipt') {
                $this->workReceipt($id, $generation, $run);
                return;
            }
            $request = $run['request'];
            try {
                $final = $this->engine->run($request, function (TransactionState $progress) use ($id, $generation): bool {
                    // Checked and claimed under the lock: a cancel either lands before
                    // this (nothing is sent) or after it (an unknown outcome).
                    return $this->locked(function () use ($id, $generation, $progress): bool {
                        $current = $this->read($id);
                        if ($current === null || $current['generation'] !== $generation || !$current['state'] instanceof StateChecking) {
                            return false;
                        }
                        $this->update($current, $progress);
                        return true;
                    });
                });
            } catch (\Throwable $e) {
                // The engine answers every failure itself; this is the last line, so
                // that a run can never be left spinning.
                $final = $request->type->movesMoney()
                    ? new StateFailed('Unexpected error: ' . $e->getMessage(), $request->merchantReference !== '' ? $request->merchantReference : TransactionEngine::newReference())
                    : new StateFailed('Unexpected error: ' . $e->getMessage());
            }
            $this->locked(function () use ($id, $generation, $final): void {
                $current = $this->read($id);
                // Cancelled, followed up or acknowledged meanwhile: this answer is
                // no longer what the screen shows. (The SDK's journal still has it.)
                if ($current === null || $current['generation'] !== $generation || !$current['state']->isBusy()) {
                    return;
                }
                $this->update($current, $final);
            });
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
            @unlink($this->workerLockPath($id, $generation));
        }
    }

    /** @param array<string, mixed> $run */
    private function workReceipt(string $id, int $generation, array $run): void
    {
        $state = $run['state'];
        if (!$state instanceof StateInquired) {
            return;
        }
        try {
            $receipt = $this->engine->fetchReceipt($state);
        } catch (\Throwable $e) {
            $receipt = new ReceiptUnavailable('', $e->getMessage(), '');
        }
        $this->locked(function () use ($id, $generation, $receipt): void {
            $current = $this->read($id);
            // The operator may have moved on while this was in flight.
            if ($current !== null && $current['generation'] === $generation && $current['state'] instanceof StateInquired) {
                $this->update($current, $current['state']->withReceipt($receipt, false));
            }
        });
    }

    // ── Internals; callers hold the lock ──────────────────────────────

    /**
     * Claims the terminal and starts `$request` in a fresh worker generation.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function begin(array $run, TransactionRequest $request): array
    {
        $run['generation']++;
        $run['busySince'] = $this->now();
        $run['spawnedAt'] = $this->now();
        $run['request'] = $request;
        $run = $this->update($run, new StateChecking());
        try {
            $this->spawner->start($run['id'], $run['generation'], 'run');
        } catch (\Throwable $e) {
            $run = $this->update($run, new StateFailed('The transaction could not be started on the server: ' . $e->getMessage()));
        }
        return $run;
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function update(array $run, TransactionState $state): array
    {
        $run['state'] = $state;
        $run['version']++;
        $run['updated'] = $this->now();
        $this->write($run);
        return $run;
    }

    /** The id of the run busy on `$serial`, or null. */
    private function runningOn(string $serial): ?string
    {
        foreach ($this->all() as $run) {
            if ($run['terminalSerial'] === $serial && $run['state']->isBusy()) {
                return $run['id'];
            }
        }
        return null;
    }

    /** Drops finished runs nobody acknowledged for too long, and settles runs whose worker died. */
    private function sweep(): void
    {
        $cutoff = $this->now() - self::FINISHED_RETENTION_SECONDS;
        foreach ($this->all() as $run) {
            $state = $run['state'];
            $waiting = $state->isBusy() || ($state instanceof StateInquired && $state->fetchingReceipt);
            if (!$waiting && $run['updated'] < $cutoff) {
                $this->remove($run['id']);
                continue;
            }
            if ($waiting && $this->now() - $run['spawnedAt'] > self::WORKER_START_GRACE_SECONDS && !$this->workerAlive($run['id'], $run['generation'])) {
                $this->update($run, $this->orphaned($run));
            }
        }
    }

    /**
     * What a run becomes when its worker is gone: never "not done". A sale, void
     * or refund that may have been sent keeps its reference.
     *
     * @param array<string, mixed> $run
     */
    private function orphaned(array $run): TransactionState
    {
        $state = $run['state'];
        if ($state instanceof StateInquired) {
            return $state->withReceipt(new ReceiptUnavailable('', 'The receipt request stopped unexpectedly on the server', ''), false);
        }
        $request = $run['request'];
        $message = 'The transaction stopped unexpectedly on the server.';
        if ($request->type->movesMoney()) {
            return $state instanceof StateChecking && $request->merchantReference === ''
                ? new StateFailed($message . ' Nothing was sent to the terminal.')
                : new StateFailed($message . ' If the request had already reached the terminal, it may still complete.', $request->merchantReference);
        }
        return new StateFailed($message, $request->looksUpByReference() ? $request->originalReference : null, 'An inquiry only reads and changes nothing, so it is safe to try again.');
    }

    /** @param array<string, mixed> $run */
    private function snapshot(array $run): TransactionSnapshot
    {
        $state = $run['state'];
        $waiting = $state->isBusy() || ($state instanceof StateInquired && $state->fetchingReceipt);
        return new TransactionSnapshot(
            $run['id'],
            $run['terminalSerial'],
            $run['request'],
            $run['usesWebService'],
            $state,
            $run['version'],
            $run['timeoutSeconds'],
            $waiting ? max(0, $run['timeoutSeconds'] - (int) floor($this->now() - $run['busySince'])) : null,
        );
    }

    /** @return list<array<string, mixed>> */
    private function all(): array
    {
        $runs = [];
        foreach (glob($this->directory . \DIRECTORY_SEPARATOR . '*.run') ?: [] as $file) {
            $run = $this->read(basename($file, '.run'));
            if ($run !== null) {
                $runs[] = $run;
            }
        }
        return $runs;
    }

    /** @return array<string, mixed>|null */
    private function read(string $id): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
            return null;
        }
        $text = @file_get_contents($this->path($id));
        if ($text === false) {
            return null;
        }
        $data = json_decode($text, true);
        if (!\is_array($data)) {
            return null;
        }
        $request = @unserialize((string) base64_decode((string) ($data['request'] ?? ''), true), ['allowed_classes' => self::ALLOWED_CLASSES]);
        $state = @unserialize((string) base64_decode((string) ($data['state'] ?? ''), true), ['allowed_classes' => self::ALLOWED_CLASSES]);
        if (!$request instanceof TransactionRequest || !$state instanceof TransactionState) {
            return null;
        }
        $data['request'] = $request;
        $data['state'] = $state;
        return $data;
    }

    /** @param array<string, mixed> $run */
    private function write(array $run): void
    {
        $stored = $run;
        $stored['request'] = base64_encode(serialize($run['request']));
        $stored['state'] = base64_encode(serialize($run['state']));
        $stored['stateName'] = $run['state']->name(); // for a human reading the folder
        $temp = $this->path($run['id']) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, json_encode($stored, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)) === false || !@rename($temp, $this->path($run['id']))) {
            @unlink($temp);
            throw new \RuntimeException('The transaction state could not be saved in ' . $this->directory);
        }
    }

    private function remove(string $id): void
    {
        @unlink($this->path($id));
    }

    private function path(string $id): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $id . '.run';
    }

    private function workerLockPath(string $id, int $generation): string
    {
        return $this->directory . \DIRECTORY_SEPARATOR . $id . '.' . $generation . '.worker';
    }

    /** @return resource */
    private function workerLock(string $id, int $generation)
    {
        $handle = fopen($this->workerLockPath($id, $generation), 'c');
        if ($handle === false || !flock($handle, \LOCK_EX)) {
            throw new \RuntimeException('The worker lock could not be taken');
        }
        return $handle;
    }

    private function workerAlive(string $id, int $generation): bool
    {
        $path = $this->workerLockPath($id, $generation);
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return true; // cannot tell: assume alive rather than invent an outcome
        }
        $free = flock($handle, \LOCK_EX | \LOCK_NB);
        if ($free) {
            flock($handle, \LOCK_UN);
        }
        fclose($handle);
        return !$free;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function locked(callable $work): mixed
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The runs folder cannot be created: ' . $this->directory);
        }
        $lock = fopen($this->directory . \DIRECTORY_SEPARATOR . '.registry.lock', 'c');
        if ($lock === false || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('The transaction registry could not be locked');
        }
        try {
            return $work();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
