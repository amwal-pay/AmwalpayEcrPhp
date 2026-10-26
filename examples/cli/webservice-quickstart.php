<?php

declare(strict_types=1);

/**
 * ECR Web Service, start to finish: HTTPS to the Amwal Hub, which relays to the
 * terminal in Web Service mode (ecrMode 4). Copy from here into your own till.
 *
 *   $env:AMWAL_ECR_WEBSERVICE_KEY = '<hex key>'   (the Web Service key, not the Wi-Fi key)
 *   php cli/webservice-quickstart.php <merchantId> <terminalId> <serial> [SIT|UAT|PROD] [amount]
 *
 * Needs Hub credentials from Amwal; there is no fake Hub for this script.
 */

require __DIR__ . '/../vendor/autoload.php';

use AmwalPay\Ecr\EcrConfig;
use AmwalPay\Ecr\EcrEnvironment;
use AmwalPay\Ecr\EcrSessions;
use AmwalPay\Ecr\Link\WebServiceLink;
use AmwalPay\Ecr\Result\Approved;
use AmwalPay\Ecr\Result\Declined;
use AmwalPay\Ecr\Result\Failed;
use AmwalPay\Ecr\Result\Found;
use AmwalPay\Ecr\Result\InquiryFailed;
use AmwalPay\Ecr\Result\NotFound;

if ($argc < 4) {
    fwrite(STDERR, "usage: php cli/webservice-quickstart.php <merchantId> <terminalId> <serial> [SIT|UAT|PROD] [amount]\n");
    exit(2);
}
[, $merchantId, $terminalId, $serial] = $argv;
$environment = EcrEnvironment::fromName($argv[4] ?? 'SIT');
$amount = $argv[5] ?? '1.000';

// 1. Plan: numeric IDs, the Web Service key, the environment. The Hub address stays inside the SDK.
$plan = EcrSessions::plan(
    new WebServiceLink($merchantId, $terminalId),
    new EcrConfig(
        environment: $environment,
        ecrId: 'TILL-01',
        stateDirectory: __DIR__ . '/../var/state',
        // caBundlePath: 'C:\php\extras\ssl\cacert.pem',   // if PHP has no CA bundle configured
    ),
    rawSecureHashKey: getenv('AMWAL_ECR_WEBSERVICE_KEY') ?: '',
);
if (!$plan->isReady()) {
    fwrite(STDERR, implode("\n", $plan->issues()) . "\n");
    exit(1);
}
echo 'Connection: ', $plan->connectionSummary(), "\n";       // e.g. "UAT · merchant 10001 · terminal 20002"

$session = EcrSessions::open($serial, $plan);

// 2. Reference first, saved with the order.
$reference = EcrSessions::newMerchantReference();
echo "Order reference $reference (saved before sending). Waiting up to 160 s for card and PIN...\n";

$result = $session->sale($amount, $reference);

switch (true) {
    case $result instanceof Approved:
        echo "Approved {$result->amount} · RRN {$result->rrn} · Auth {$result->authCode}\n";
        // Close receipt on Web Service needs the sale's reference.
        $closed = $session->closeReceipt(originalMerchantReference: $reference);
        echo "Close receipt: {$closed->outcome()}\n";
        break;

    case $result instanceof Declined:
        echo "Declined: {$result->reason} (code {$result->responseCode})\n";
        break;

    case $result instanceof Failed && $result->failure->outcomeUnknown():
        // Web Service never inquires by itself: the inquiry is yours. Never resend.
        echo "OUTCOME UNKNOWN: {$result->failure->message}\nInquiring by reference $reference ...\n";
        $inquiry = $session->inquireByReference($reference);   // no date: do not invent "today"
        echo match (true) {
            $inquiry instanceof Found => "Found: {$inquiry->transaction->status} {$inquiry->transaction->amount}\n",
            $inquiry instanceof NotFound => "The Hub has no record yet — check the merchant portal before taking payment again.\n",
            $inquiry instanceof InquiryFailed => "Inquiry failed ({$inquiry->failure->message}) — inquire again later; it moves no money.\n",
        };
        break;

    case $result instanceof Failed:
        echo "Not sent: {$result->failure->message}\n";
        break;
}
