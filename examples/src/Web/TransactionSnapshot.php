<?php

declare(strict_types=1);

namespace AmwalExample\Web;

use AmwalExample\Core\StateInquired;
use AmwalExample\Core\TransactionRequest;
use AmwalExample\Core\TransactionState;

/**
 * One operator flow at a moment, read under the registry's lock so state and
 * version belong together.
 *
 * @param int $version Bumped on every change, so a poller can tell whether anything moved.
 * @param int $timeoutSeconds The configured response budget: 160 s on Web Service, 120 s on Wi-Fi.
 * @param int|null $remainingSeconds What is left while busy or fetching a receipt; null otherwise.
 */
final class TransactionSnapshot
{
    public function __construct(
        public readonly string $id,
        public readonly string $terminalSerial,
        public readonly TransactionRequest $request,
        public readonly bool $usesWebService,
        public readonly TransactionState $state,
        public readonly int $version,
        public readonly int $timeoutSeconds,
        public readonly ?int $remainingSeconds,
    ) {
    }

    public function fetchingReceipt(): bool
    {
        return $this->state instanceof StateInquired && $this->state->fetchingReceipt;
    }
}
