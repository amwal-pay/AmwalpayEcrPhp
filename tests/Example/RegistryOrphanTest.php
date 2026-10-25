<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

use AmwalExample\Core\EcrExampleSettings;
use AmwalExample\Core\LogBuffer;
use AmwalExample\Core\SecretProtector;
use AmwalExample\Core\StateFailed;
use AmwalExample\Core\TerminalRepository;
use AmwalExample\Core\TransactionEngine;
use AmwalExample\Core\TransactionRequest;
use AmwalExample\Web\DataPaths;
use AmwalExample\Web\Spawner;
use AmwalExample\Web\TransactionRegistry;
use AmwalPay\Ecr\EcrTransactionType;
use AmwalPay\Ecr\Tests\Support\TempState;
use PHPUnit\Framework\TestCase;

/**
 * A worker that dies (the web server recycled, the machine slept, a fatal
 * error) must not leave a run spinning forever, and must not turn a sale that
 * may have been sent into "not done". The web sample keeps runs in memory and
 * lists this as a limitation; the PHP port detects it.
 */
final class RegistryOrphanTest extends TestCase
{
    use TempState;

    private float $now = 1_000_000.0;

    private function registry(): TransactionRegistry
    {
        $paths = new DataPaths($this->stateDir());
        $paths->ensure();
        $engine = new TransactionEngine(
            new TerminalRepository($paths->terminals()),
            new EcrExampleSettings($paths->settings(), new SecretProtector($paths->keys())),
            new LogBuffer($paths->log()),
            $paths->sdkState(),
        );
        // A worker that never starts: exactly what a crash looks like from outside.
        $spawner = new class ($paths) extends Spawner {
            public function start(string $runId, int $generation, string $mode): void
            {
            }
        };
        return new TransactionRegistry($paths->runs(), $engine, $spawner, fn (): float => $this->now);
    }

    public function testASaleWhoseWorkerDiedBecomesAnUnknownOutcomeWithItsReference(): void
    {
        $registry = $this->registry();
        [, $run] = $registry->start(new TransactionRequest(EcrTransactionType::Sale, 'SN-1', '1.000'), false);
        $reference = $run->request->merchantReference;

        self::assertMatchesRegularExpression('/^[0-9A-F]{12}$/', $reference, 'made and stored before anything is sent');
        $this->now += 5;
        self::assertTrue($registry->find($run->id)?->state->isBusy(), 'within the start-up grace it is still running');

        $this->now += 20;
        $after = $registry->find($run->id);

        self::assertInstanceOf(StateFailed::class, $after?->state);
        self::assertSame($reference, $after->state->inquirableReference);
        self::assertStringContainsString('stopped unexpectedly', $after->state->message);
        self::assertSame(['started'], [$registry->start(new TransactionRequest(EcrTransactionType::Sale, 'SN-1', '1.000'), false)[0]], 'the terminal is free again');
    }

    public function testFinishedRunsNobodyReadAreKeptTwelveHoursThenDropped(): void
    {
        $registry = $this->registry();
        [, $run] = $registry->start(new TransactionRequest(EcrTransactionType::Inquiry, 'SN-2', originalReference: 'ORD-1'), false);
        $this->now += 30;
        self::assertInstanceOf(StateFailed::class, $registry->find($run->id)?->state);

        $this->now += TransactionRegistry::FINISHED_RETENTION_SECONDS - 60;
        self::assertNotNull($registry->find($run->id));
        $this->now += 120;
        self::assertNull($registry->find($run->id));
    }
}
