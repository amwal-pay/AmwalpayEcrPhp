<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

/**
 * What the example till gained with the Windows samples' 2026-10 updates: manual
 * settlement, Web Service Check reachability, and terminals on a USB cable.
 */
final class SettlementAndCableTest extends WebExampleTestCase
{
    public function testSettlementClosesTheBatchThenHasNothingToSettle(): void
    {
        $this->registerWifi();
        $sale = $this->start(['type' => 'Sale', 'amountDigits' => '1500']);
        $this->settle($sale['id']);
        $this->call('DELETE', '/api/transactions/' . $sale['id'], []);

        $started = $this->call('POST', '/api/transactions/settlement', ['terminalSerial' => self::SERIAL]);
        self::assertSame(202, $started['status']);
        self::assertSame('Settlement', $started['json']['operation']);

        $done = $this->settle($started['json']['id']);
        self::assertSame('settled', $done['state']);
        self::assertTrue($done['isResult']);
        $dialog = $done['dialog'];
        self::assertSame('Settled', $dialog['headline']);
        self::assertSame('approved', $dialog['tone']);
        self::assertSame('1.500', $dialog['amount']);
        self::assertTrue($dialog['canCloseReceipt']);
        self::assertFalse($dialog['isUnknownOutcome']);
        $fields = array_column($dialog['fields'], 'value', 'label');
        self::assertSame('00000007', $fields['Batch']);
        self::assertSame('1', $fields['Transactions']);
        self::assertSame('1 · 1.500', $fields['Sale']);
        self::assertSame('0 · 0.000', $fields['Capture']);
        self::assertArrayHasKey('Closed', $fields);

        $closed = $this->call('POST', '/api/transactions/' . $done['id'] . '/close-receipt', []);
        self::assertSame(200, $closed['status']);
        self::assertSame('Terminal is back on its idle screen', $closed['json']['message']);
        $this->call('DELETE', '/api/transactions/' . $done['id'], []);

        $again = $this->settle($this->call('POST', '/api/transactions/settlement', ['terminalSerial' => self::SERIAL])['json']['id']);
        self::assertSame('Nothing to settle', $again['dialog']['headline']);
        self::assertSame('approved', $again['dialog']['tone']);
    }

    public function testSettlementIsRefusedForAMissingOrUnreadyTerminal(): void
    {
        $missing = $this->call('POST', '/api/transactions/settlement', []);
        self::assertSame(422, $missing['status']);
        self::assertSame('Choose a POS terminal', $missing['json']['errors']['terminal']);

        $gone = $this->call('POST', '/api/transactions/settlement', ['terminalSerial' => 'NOPE']);
        self::assertSame('Terminal NOPE is no longer registered', $gone['json']['errors']['terminal']);

        $this->call('POST', '/api/terminals', ['name' => 'BT', 'serialNumber' => 'BT-1', 'ecrMode' => 3]);
        $bluetooth = $this->call('POST', '/api/transactions/settlement', ['terminalSerial' => 'BT-1']);
        self::assertSame(422, $bluetooth['status']);
        self::assertSame('Settlement is not available for this terminal mode', $bluetooth['json']['message']);

        $this->call('POST', '/api/terminals', ['name' => 'Hub', 'serialNumber' => 'WS-1', 'ecrMode' => 4, 'merchantId' => '1', 'terminalId' => '2']);
        $noKey = $this->call('POST', '/api/transactions/settlement', ['terminalSerial' => 'WS-1']);
        self::assertSame(422, $noKey['status']);
        self::assertStringContainsString('Web Service secure hash key is not configured', $noKey['json']['message']);
    }

    public function testAnUnreachableTerminalMeansTheSettlementIsNotCompleted(): void
    {
        $this->registerWifi();
        $this->terminals[0]->stop();

        $done = $this->settle($this->call('POST', '/api/transactions/settlement', ['terminalSerial' => self::SERIAL])['json']['id']);

        self::assertSame('failed', $done['state']);
        self::assertSame('Not completed', $done['dialog']['headline']);
        self::assertFalse($done['dialog']['canCloseReceipt']);
    }

    public function testCheckReachabilityIsWebServiceOnlyAndStartsNothing(): void
    {
        self::assertSame(422, $this->call('POST', '/api/transactions/reachability', [])['status']);

        $this->registerWifi();
        $wifi = $this->call('POST', '/api/transactions/reachability', ['terminalSerial' => self::SERIAL]);
        self::assertSame(200, $wifi['status']);
        self::assertSame(['reachable' => false, 'message' => 'Check reachability is only available for Web Service'], $wifi['json']);

        $this->call('POST', '/api/terminals', ['name' => 'Hub', 'serialNumber' => 'WS-1', 'ecrMode' => 4, 'merchantId' => '1', 'terminalId' => '2']);
        $noKey = $this->call('POST', '/api/transactions/reachability', ['terminalSerial' => 'WS-1']);
        self::assertFalse($noKey['json']['reachable']);
        self::assertStringContainsString('Web Service secure hash key is not configured', $noKey['json']['message']);

        self::assertSame([], $this->call('GET', '/api/transactions')['json']['transactions']);
    }

    public function testAUsbTerminalNeedsANameASerialAndItsDevice(): void
    {
        $missing = $this->call('POST', '/api/terminals', ['name' => 'Cable', 'serialNumber' => 'U1', 'ecrMode' => 1]);
        self::assertSame(422, $missing['status']);
        self::assertSame('Choose the USB device this cable uses', $missing['json']['errors']['comPort']);

        $bad = $this->call('POST', '/api/terminals', ['name' => 'Cable', 'serialNumber' => 'U1', 'ecrMode' => 1, 'comPort' => 'COM 8']);
        self::assertSame('Choose a USB device. Windows assigns its COM port from the device serial', $bad['json']['errors']['comPort']);

        $ok = $this->call('POST', '/api/terminals', ['name' => 'Cable', 'serialNumber' => 'U1', 'ecrMode' => 1, 'comPort' => 'NO-SUCH-CABLE']);
        self::assertSame(200, $ok['status']);
        self::assertSame('NO-SUCH-CABLE', $ok['json']['comPort']);
        self::assertSame('NO-SUCH-CABLE', $ok['json']['connectionSummary']);
        self::assertTrue($ok['json']['supported']);

        // Survives the file, and another mode does not keep it.
        $listed = $this->call('GET', '/api/terminals')['json']['terminals'];
        self::assertSame('NO-SUCH-CABLE', $listed[0]['comPort']);
        $wifi = $this->call('PUT', '/api/terminals/U1', ['name' => 'Cable', 'serialNumber' => 'U1', 'ecrMode' => 2, 'ipAddress' => '127.0.0.1', 'comPort' => 'NO-SUCH-CABLE']);
        self::assertSame('', $wifi['json']['comPort']);
    }

    public function testACableThatIsNotPluggedInCannotStartASale(): void
    {
        $this->call('PUT', '/api/settings/keys/wifi', ['value' => self::KEY]);
        $this->call('POST', '/api/terminals', ['name' => 'Cable', 'serialNumber' => 'U1', 'ecrMode' => 1, 'comPort' => 'NO-SUCH-CABLE']);

        $sale = $this->call('POST', '/api/transactions', ['terminalSerial' => 'U1', 'type' => 'Sale', 'amountDigits' => '1500']);
        self::assertSame(422, $sale['status']);
        self::assertStringContainsString('Plug in device NO-SUCH-CABLE', $sale['json']['message']);

        $settlement = $this->call('POST', '/api/transactions/settlement', ['terminalSerial' => 'U1']);
        self::assertSame(422, $settlement['status']);
        self::assertStringContainsString('Plug in device NO-SUCH-CABLE', $settlement['json']['message']);
    }

    public function testTheSerialPortsRouteListsWhatIsPluggedIn(): void
    {
        $ports = $this->call('GET', '/api/serial-ports?serial=U1&saved=NO-SUCH-CABLE');

        self::assertSame(200, $ports['status']);
        self::assertIsArray($ports['json']['ports']);
        self::assertArrayHasKey('selected', $ports['json']);
    }
}
