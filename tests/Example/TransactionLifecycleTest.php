<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

/**
 * Server-owned transactions end to end, through worker processes and the
 * signed fake terminal: what the web sample's FormValidationTests,
 * TransactionLifecycleTests and ReceiptTests pin.
 */
final class TransactionLifecycleTest extends WebExampleTestCase
{
    public function testFormErrorsAreCoresMessagesAndNothingStarts(): void
    {
        $this->registerWifi();
        $errors = fn (array $form): array => $this->call('POST', '/api/transactions', ['terminalSerial' => self::SERIAL] + $form)['json']['errors'] ?? [];

        self::assertSame(['amount' => 'Required field'], $errors(['type' => 'Sale', 'amountDigits' => '']));
        self::assertSame(['amount' => 'minimum amount allowed is 0.010'], $errors(['type' => 'Sale', 'amountDigits' => '9']));
        self::assertSame(['merchantReference' => "Use printable ASCII without spaces, '&' or '='"], $errors(['type' => 'Sale', 'amountDigits' => '1000', 'merchantReference' => 'A B']));
        self::assertSame(['merchantReference' => 'A merchant reference is at most 32 characters'], $errors(['type' => 'Sale', 'amountDigits' => '1000', 'merchantReference' => str_repeat('X', 33)]));
        self::assertSame(['receiptNumber' => 'Enter the receipt number of the transaction to act on'], $errors(['type' => 'Void', 'receiptNumber' => 'ab']));
        self::assertSame(['originalTerminalId' => 'Enter the terminal the original was taken on'], $errors(['type' => 'Void', 'receiptNumber' => '24', 'useOtherTerminal' => true]));
        self::assertSame(['originalReference' => 'Enter the reference the transaction was sent with'], $errors(['type' => 'Inquiry', 'lookUpByReference' => true]));
        self::assertSame(['type' => 'Choose a transaction type'], $errors(['type' => 'Settlement']));
        self::assertSame(['terminal' => 'Choose a POS terminal'], $this->call('POST', '/api/transactions', ['type' => 'Sale'])['json']['errors']);
        self::assertSame(['terminal' => 'Terminal GONE is no longer registered'], $this->call('POST', '/api/transactions', ['terminalSerial' => 'GONE', 'type' => 'Sale'])['json']['errors']);
        self::assertSame([], $this->call('GET', '/api/transactions')['json']['transactions']);
    }

    public function testAPlanThatIsNotReadyRefusesToStartWithItsIssues(): void
    {
        $this->call('POST', '/api/terminals', ['name' => 'Demo', 'serialNumber' => self::SERIAL, 'ecrMode' => 2, 'ipAddress' => '127.0.0.1']);

        $refused = $this->call('POST', '/api/transactions', ['terminalSerial' => self::SERIAL, 'type' => 'Sale', 'amountDigits' => '1000']);

        self::assertSame(422, $refused['status']);
        self::assertSame([], (array) $refused['json']['errors']);
        self::assertSame('Wi-Fi/CABLE secure hash key is not configured', $refused['json']['message']);
    }

    public function testAnApprovedSaleCarriesTheDialogAndCanCloseTheReceipt(): void
    {
        $this->registerWifi();

        $started = $this->start(['type' => 'Sale', 'amountDigits' => '1234', 'merchantReference' => 'ORDER-1001']);
        self::assertSame('checking', $started['state']);
        self::assertTrue($started['busy']);
        self::assertSame('Checking the terminal…', $started['statusText']);
        self::assertSame(120, $started['timeoutSeconds']);
        self::assertIsInt($started['remainingSeconds']);

        $done = $this->settle($started['id']);
        self::assertSame('completed', $done['state']);
        self::assertGreaterThan($started['version'], $done['version']);
        self::assertNull($done['remainingSeconds']);
        $dialog = $done['dialog'];
        self::assertSame('Approved', $dialog['headline']);
        self::assertSame('approved', $dialog['tone']);
        self::assertSame('1.234', $dialog['amount']);
        self::assertSame(['Auth code', 'Card', 'Reference'], array_column($dialog['fields'], 'label'));
        self::assertSame('ORDER-1001', $dialog['fields'][2]['value']);
        self::assertTrue($dialog['canCloseReceipt']);
        self::assertStringContainsString('"success": true', $dialog['rawPayload']);

        $closed = $this->call('POST', '/api/transactions/' . $started['id'] . '/close-receipt');
        self::assertSame('Terminal is back on its idle screen', $closed['json']['message']);
        self::assertSame('completed', $closed['json']['transaction']['state'], 'closing the receipt leaves the dialog open');

        self::assertSame(204, $this->call('DELETE', '/api/transactions/' . $started['id'])['status']);
        self::assertSame(404, $this->call('GET', '/api/transactions/' . $started['id'])['status']);
        self::assertSame(204, $this->call('DELETE', '/api/transactions/' . $started['id'])['status'], 'acknowledging twice is fine');
    }

    public function testABlankReferenceIsGeneratedAndADeclineIsADecline(): void
    {
        $this->registerWifi(flags: ['--decline', '51']);

        $done = $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '1000'])['id']);

        self::assertSame('completed', $done['state']);
        self::assertSame('Declined', $done['dialog']['headline']);
        self::assertSame('Declined by the issuer (51)', $done['dialog']['message']);
        self::assertFalse($done['dialog']['isUnknownOutcome']);
        self::assertMatchesRegularExpression('/"merchantReference":"[0-9A-F]{12}"/', $this->log());
    }

    public function testAPartialApprovalIsCalledOut(): void
    {
        $this->registerWifi(flags: ['--partial']);

        $dialog = $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '961100'])['id'])['dialog'];

        self::assertSame('Partially approved', $dialog['headline']);
        self::assertSame('480.550', $dialog['amount']);
        self::assertSame('Only 480.550 of 961.100 was approved. Collect the difference by another means.', $dialog['message']);
    }

    public function testAnUnreachableTerminalIsNotCompletedAndNotUnknown(): void
    {
        $this->call('POST', '/api/terminals', ['name' => 'Off', 'serialNumber' => self::SERIAL, 'ecrMode' => 2, 'ipAddress' => '127.0.0.1', 'port' => '1']);
        $this->call('PUT', '/api/settings/keys/wifi', ['value' => self::KEY]);

        $dialog = $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '1000'])['id'])['dialog'];

        self::assertSame('Not completed', $dialog['headline']);
        self::assertFalse($dialog['isUnknownOutcome']);
        self::assertStringContainsString('127.0.0.1:1 is not reachable', $dialog['message']);
    }

    public function testALostAnswerIsRecoveredByTheAutomaticInquiry(): void
    {
        $this->registerWifi(flags: ['--drop-sale']);

        $done = $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '2500', 'merchantReference' => 'ORDER-2'])['id']);

        self::assertSame('inquired', $done['state']);
        self::assertSame('Approved', $done['dialog']['headline']);
        self::assertSame("The terminal's answer to this request was lost, but it was asked automatically and knows this transaction. Do not send it again.", $done['dialog']['note']);
    }

    public function testALostAnswerAndALostFollowUpOfferOnlyAnInquiryOnTheSameRun(): void
    {
        $port = $this->registerWifi(flags: ['--drop-sale', '--drop-first-inquiry']);
        $id = $this->start(['type' => 'Sale', 'amountDigits' => '3000', 'merchantReference' => 'ORDER-3'])['id'];

        $unknown = $this->settle($id);
        self::assertSame('failed', $unknown['state']);
        self::assertSame('Outcome unknown', $unknown['dialog']['headline']);
        self::assertTrue($unknown['dialog']['isUnknownOutcome']);
        self::assertSame('ORDER-3', $unknown['dialog']['inquirableReference']);
        self::assertStringStartsWith('The transaction may have completed.', $unknown['dialog']['unknownOutcomeWarning']);

        $inquiry = $this->call('POST', '/api/transactions/' . $id . '/inquire');
        self::assertSame(202, $inquiry['status']);
        self::assertSame($id, $inquiry['json']['id'], 'the same run');
        self::assertSame('Inquiry', $inquiry['json']['operation']);

        $found = $this->settle($id);
        self::assertSame('inquired', $found['state']);
        self::assertSame('Approved', $found['dialog']['headline']);
        self::assertSame(409, $this->call('POST', '/api/transactions/' . $id . '/inquire')['status'], 'no unknown outcome left to look up');
        self::assertSame(1, substr_count($this->terminals[0]->output(), '"messageType":"SALE"'), 'exactly one sale reached the terminal');
        unset($port);
    }

    public function testCancelIsAnUnknownOutcomeNeverCancelled(): void
    {
        $this->registerWifi(flags: ['--delay', '5']);
        $id = $this->start(['type' => 'Sale', 'amountDigits' => '700'])['id'];
        $deadline = microtime(true) + 10;
        do {
            $view = $this->call('GET', '/api/transactions/' . $id)['json'];
            usleep(100_000);
        } while ($view['state'] !== 'inProgress' && microtime(true) < $deadline);

        $cancelled = $this->call('POST', '/api/transactions/' . $id . '/cancel')['json'];

        self::assertSame('failed', $cancelled['state']);
        self::assertSame('Outcome unknown', $cancelled['dialog']['headline']);
        self::assertStringStartsWith('You stopped waiting for the terminal.', $cancelled['dialog']['message']);
        self::assertMatchesRegularExpression('/^[0-9A-F]{12}$/', $cancelled['dialog']['inquirableReference']);
        sleep(6);
        self::assertSame($cancelled['version'], $this->call('GET', '/api/transactions/' . $id)['json']['version'], 'the late answer does not overwrite it');
    }

    public function testOneRunningTransactionPerTerminalButTerminalsRunSideBySide(): void
    {
        $this->registerWifi(flags: ['--delay', '3']);
        $this->registerWifi('TW-OTHER', ['--delay', '3']);

        $first = $this->start(['type' => 'Sale', 'amountDigits' => '1000']);
        $second = $this->call('POST', '/api/transactions', ['terminalSerial' => self::SERIAL, 'type' => 'Sale', 'amountDigits' => '1000']);
        $other = $this->call('POST', '/api/transactions', ['terminalSerial' => 'TW-OTHER', 'type' => 'Sale', 'amountDigits' => '1000']);

        self::assertSame(409, $second['status']);
        self::assertSame('A transaction is already running on this terminal', $second['json']['error']);
        self::assertSame($first['id'], $second['json']['transactionId']);
        self::assertSame(202, $other['status']);
        self::assertSame(409, $this->call('DELETE', '/api/transactions/' . $first['id'])['status'], 'a running transaction cannot be dismissed');

        $pending = $this->call('GET', '/api/transactions')['json']['transactions'];
        self::assertSame([$other['json']['id'], $first['id']], array_column($pending, 'id'), 'newest first, for a reloaded page');
        self::assertSame('completed', $this->settle($first['id'])['state']);
        self::assertSame('completed', $this->settle($other['json']['id'])['state']);
    }

    public function testConcurrentStartsOnOneTerminalLetExactlyOneThrough(): void
    {
        $this->registerWifi(flags: ['--delay', '2']);
        $multi = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 8; $i++) {
            $handle = curl_init($this->base . '/api/transactions');
            curl_setopt_array($handle, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => json_encode(['terminalSerial' => self::SERIAL, 'type' => 'Sale', 'amountDigits' => '1000']),
                \CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                \CURLOPT_RETURNTRANSFER => true,
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);
        $codes = array_map(static fn ($h): int => (int) curl_getinfo($h, \CURLINFO_RESPONSE_CODE), $handles);

        self::assertSame(1, \count(array_keys($codes, 202, true)), json_encode($codes));
        self::assertSame(7, \count(array_keys($codes, 409, true)));
    }

    public function testAnInquiryFindsTheSaleAndItsReceiptIsAQrCode(): void
    {
        $this->registerWifi();
        $sale = $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '1234', 'merchantReference' => 'ORDER-R'])['id']);
        $this->call('DELETE', '/api/transactions/' . $sale['id']);

        $id = $this->start(['type' => 'Inquiry', 'lookUpByReference' => true, 'originalReference' => 'ORDER-R'])['id'];
        $found = $this->settle($id);
        self::assertSame('inquired', $found['state']);
        self::assertSame(['Receipt number', 'Type', 'Card', 'Date/Time'], array_column($found['dialog']['fields'], 'label'));
        self::assertContains('Can be voided', $found['dialog']['hints']);
        self::assertTrue($found['dialog']['receipt']['showButton']);
        self::assertFalse($found['dialog']['canCloseReceipt']);

        self::assertSame(202, $this->call('POST', '/api/transactions/' . $id . '/receipt')['status']);
        $receipt = $this->settle($id)['dialog']['receipt'];
        self::assertStringStartsWith('https://example.invalid/receipt?stan=', $receipt['url']);
        self::assertFalse($receipt['showButton']);
        self::assertSame(409, $this->call('POST', '/api/transactions/' . $id . '/receipt')['status'], 'already has one');

        $qr = $this->call('GET', '/api/qr?text=' . rawurlencode($receipt['url']));
        self::assertSame(200, $qr['status']);
        self::assertSame('image/svg+xml', $qr['headers']['content-type']);
        self::assertStringContainsString('<svg', $qr['body']);
        self::assertSame(400, $this->call('GET', '/api/qr?text=')['status']);
        self::assertSame(400, $this->call('GET', '/api/qr?text=' . str_repeat('a', 1025))['status']);
    }

    public function testVoidAndRefundRunWithTheirOwnFields(): void
    {
        $this->registerWifi();

        $void = $this->settle($this->start(['type' => 'Void', 'receiptNumber' => '24', 'useOtherTerminal' => true, 'originalTerminalId' => '30001'])['id']);
        $refund = $this->settle($this->start(['type' => 'Refund', 'amountDigits' => '500', 'receiptNumber' => '24', 'originalDate' => '2026-10-20'])['id']);

        self::assertSame('Approved', $void['dialog']['headline']);
        self::assertNotContains('Auth code', array_column($void['dialog']['fields'], 'label'), 'a void has no authorisation of its own');
        self::assertSame('Approved', $refund['dialog']['headline']);
        $output = $this->terminals[0]->output();
        self::assertStringContainsString('"originalTerminalId":"30001"', $output);
        self::assertStringContainsString('"originalTransactionDate":"20261020"', $output);
    }

    public function testTheLogShowsTheTrafficButNeverTheKeyAndCanBeCleared(): void
    {
        $this->registerWifi();
        $this->settle($this->start(['type' => 'Sale', 'amountDigits' => '1234'])['id']);

        $log = $this->call('GET', '/api/log')['json'];

        self::assertSame(500, $log['capacity']);
        self::assertGreaterThan(5, $log['count']);
        self::assertStringContainsString('Ecr Sending SALE', $log['text']);
        self::assertStringContainsString('483312******1234', $log['text']);
        self::assertStringNotContainsStringIgnoringCase(self::KEY, $log['text']);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}\.\d{3} Ecr /m', $log['text']);
        self::assertSame(204, $this->call('DELETE', '/api/log')['status']);
        self::assertSame(0, $this->call('GET', '/api/log')['json']['count']);
    }
}
