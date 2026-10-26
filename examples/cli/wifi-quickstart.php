<?php

declare(strict_types=1);

/**
 * Wi-Fi, start to finish: open a session, sell, read the result, recover a
 * lost answer, close the receipt. Copy from here into your own till.
 *
 *   $env:AMWAL_ECR_WIFI_KEY = '<hex key>'     (PowerShell)
 *   php cli/wifi-quickstart.php <terminal-ip> [port] [amount]
 *
 * No terminal? Start the fake one first, with the same key:
 *   php ../tools/fake-pos-server.php --key <hex key> --port 19100
 *   php cli/wifi-quickstart.php 127.0.0.1 19100 1.234
 */

require __DIR__ . '/../vendor/autoload.php';

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\LanLink;
use AmwalPay\Ecr\Log\EcrLogger;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NotFound;
use AmwalPay\Ecr\Result\ReceiptClosed;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 19100);
$amount = $argv[3] ?? '1.234';                       // a string, never a float

// 1. Plan: validates the address and the key without connecting.
$plan = EcrSessions::plan(
    new LanLink($host, $port),
    new EcrConfig(
        ecrId: 'TILL-01',
        stateDirectory: __DIR__ . '/../var/state',   // the SDK's lock and journal
    ),
    rawSecureHashKey: getenv('AMWAL_ECR_WIFI_KEY') ?: '',
);
if (!$plan->isReady()) {
    fwrite(STDERR, implode("\n", $plan->issues()) . "\n");
    exit(1);
}

// 2. Open one session for the terminal and keep it.
$session = EcrSessions::open('SN-WIFI-0001', $plan, EcrLogger::from(static fn (string $line) => null));

// 3. Probe before a sale: proves the port answers (not that the terminal is idle).
$probe = $session->probeReachability();
if ($probe !== null && !$probe->reachable) {
    fwrite(STDERR, "Terminal not reachable: {$probe->endpoint} ({$probe->error})\n");
    exit(1);
}

// 4. Make the reference, save it with the order, THEN send.
$reference = EcrSessions::newMerchantReference();
echo "Order reference $reference (saved before sending)\n";

$result = $session->sale($amount, $reference);

// 5. Read the result. Never resend a Failed sale: inquire.
switch (true) {
    case $result instanceof Approved:
        echo "Approved {$result->amount} · RRN {$result->rrn} · Auth {$result->authCode} · {$result->maskedPan}\n";
        if ($result->partialApproval) {
            echo "Partial approval: asked {$result->requestedAmount}, took {$result->amount}. Collect the difference.\n";
        }
        // 6. Close receipt: from the cashier's Close receipt button, not automatically.
        $closed = $session->closeReceipt();
        echo $closed instanceof ReceiptClosed ? "Terminal back on its idle screen\n" : "Close receipt: {$closed->outcome()}\n";
        break;

    case $result instanceof Declined:
        echo "Declined: {$result->reason} (code {$result->responseCode})\n";
        break;

    case $result instanceof Failed && $result->settled():
        // The SDK asked the terminal by itself and it knew.
        $t = $result->recovered->transaction;
        echo "Answer lost, then found: {$t->status} {$t->amount} (receipt {$t->stan})\n";
        break;

    case $result instanceof Failed && $result->failure->outcomeUnknown():
        echo "OUTCOME UNKNOWN: {$result->failure->message}\n";
        echo "Do not charge again. Inquiring by reference $reference ...\n";
        $inquiry = $session->inquireByReference($reference);
        echo match (true) {
            $inquiry instanceof Found => "Found: {$inquiry->transaction->status}\n",
            $inquiry instanceof NotFound => "Not found yet — confirm on the terminal before taking payment again.\n",
            $inquiry instanceof InquiryFailed => "Inquiry failed ({$inquiry->failure->message}) — inquire again later.\n",
        };
        break;

    case $result instanceof Failed:
        // Nothing was sent: unreachable or, with the opt-in guard, busy or an earlier unknown outcome to reconcile first.
        echo "Not sent: {$result->failure->message}\n";
        break;
}
