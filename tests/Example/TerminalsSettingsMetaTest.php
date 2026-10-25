<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

/**
 * Terminals, settings, the config card and meta: what the web sample's
 * TerminalTests, SettingsTests, ConfigCardTests and MetaTests pin.
 */
final class TerminalsSettingsMetaTest extends WebExampleTestCase
{
    public function testTerminalsAreAddedEditedAndDeletedBySerialAndSurviveARestart(): void
    {
        $added = $this->call('POST', '/api/terminals', ['name' => 'Till 1', 'serialNumber' => 'SN-1', 'ecrMode' => 2, 'ipAddress' => '192.168.1.50', 'port' => '']);
        self::assertSame(200, $added['status']);
        self::assertSame(9100, $added['json']['port'], 'blank port is the standard 9100');
        self::assertSame('192.168.1.50:9100', $added['json']['connectionSummary']);
        self::assertSame('Till 1 (SN-1)', $added['json']['displayName']);

        $edited = $this->call('PUT', '/api/terminals/SN-1', ['name' => 'Till 1b', 'serialNumber' => 'SN-2', 'ecrMode' => 4, 'merchantId' => '10001', 'terminalId' => '20002']);
        self::assertSame(200, $edited['status']);
        self::assertSame('Merchant 10001 · Terminal 20002', $edited['json']['connectionSummary']);
        self::assertSame(0, $edited['json']['port']);

        $list = $this->call('GET', '/api/terminals')['json']['terminals'];
        self::assertSame(['SN-2'], array_column($list, 'serialNumber'), 'changing the serial replaces the entry');
        self::assertFileExists($this->dataDir . '/terminals.json');
        self::assertSame(404, $this->call('PUT', '/api/terminals/SN-1', ['name' => 'x', 'serialNumber' => 'x', 'ecrMode' => 2])['status']);
        self::assertSame(204, $this->call('DELETE', '/api/terminals/SN-2')['status']);
        self::assertSame(404, $this->call('DELETE', '/api/terminals/SN-2')['status']);
        self::assertSame([], $this->call('GET', '/api/terminals')['json']['terminals']);
    }

    public function testTheEditorsMessagesAreTheWindowsSamplesMessages(): void
    {
        $empty = $this->call('POST', '/api/terminals', ['ecrMode' => 2, 'ipAddress' => '300.1.1.1', 'port' => '70000']);
        self::assertSame(422, $empty['status']);
        self::assertSame([
            'name' => 'Enter a terminal name',
            'serial' => 'Enter the terminal serial number',
            'ip' => 'Enter a valid IPv4 address, for example 192.168.1.50',
            'port' => 'Port must be between 1 and 65535',
        ], $empty['json']['errors']);

        $web = $this->call('POST', '/api/terminals', ['name' => 'W', 'serialNumber' => 'W1', 'ecrMode' => 4, 'merchantId' => 'M1', 'terminalId' => '']);
        self::assertSame(['terminalId' => 'Enter the backend terminal ID', 'merchantId' => 'The merchant ID must be numeric'], $web['json']['errors']);
        self::assertSame(['mode' => 'Choose an ECR mode'], $this->call('POST', '/api/terminals', ['name' => 'x', 'serialNumber' => 'x', 'ecrMode' => 9])['json']['errors']);

        $this->call('POST', '/api/terminals', ['name' => 'A', 'serialNumber' => 'DUP', 'ecrMode' => 2, 'ipAddress' => '10.0.0.1']);
        $dup = $this->call('POST', '/api/terminals', ['name' => 'B', 'serialNumber' => 'DUP', 'ecrMode' => 2, 'ipAddress' => '10.0.0.2']);
        self::assertSame(['serial' => 'A terminal with this serial number already exists'], $dup['json']['errors']);
    }

    public function testSettingsStartUnconfiguredOnSitAndAKeyIsNeverEchoed(): void
    {
        $settings = $this->call('GET', '/api/settings')['json'];
        self::assertSame('SIT', $settings['environment']);
        self::assertSame(['label' => 'Wi-Fi/CABLE secure hash key', 'configured' => false], $settings['wifi']);
        self::assertSame(['label' => 'Web Service secure hash key', 'configured' => false], $settings['webService']);

        $saved = $this->call('PUT', '/api/settings/keys/wifi', ['value' => ' ' . self::KEY . ' ']);
        self::assertSame(200, $saved['status']);
        self::assertTrue($saved['json']['wifi']['configured']);
        self::assertFalse($saved['json']['webService']['configured'], 'two independent keys');
        self::assertStringNotContainsString(self::KEY, $saved['body']);
        self::assertStringNotContainsString(self::KEY, $this->call('GET', '/api/settings')['body']);

        self::assertSame(['value' => 'Enter the key, or use Clear to remove it'], $this->call('PUT', '/api/settings/keys/wifi', ['value' => ' '])['json']['errors']);
        self::assertSame(['value' => 'Must be an even-length hex string of at least 16 characters'], $this->call('PUT', '/api/settings/keys/webService', ['value' => 'abc'])['json']['errors']);
        self::assertSame(404, $this->call('PUT', '/api/settings/keys/usb', ['value' => self::KEY])['status']);

        self::assertSame('UAT', $this->call('PUT', '/api/settings/environment', ['environment' => 'uat'])['json']['environment']);
        self::assertSame(['environment' => 'Choose SIT, UAT or PROD'], $this->call('PUT', '/api/settings/environment', ['environment' => 'LIVE'])['json']['errors']);

        // Stored encrypted, and readable again by a fresh request.
        $file = (string) file_get_contents($this->dataDir . '/settings.dat');
        self::assertStringNotContainsStringIgnoringCase(self::KEY, $file);
        self::assertStringNotContainsString('wifiSecureHashKey', $file);
        self::assertTrue($this->call('GET', '/api/settings')['json']['wifi']['configured']);

        self::assertFalse($this->call('DELETE', '/api/settings/keys/wifi')['json']['wifi']['configured']);
    }

    public function testALostKeyFileIsANoticeAndTheKeysMustBeEnteredAgain(): void
    {
        $this->call('PUT', '/api/settings/keys/wifi', ['value' => self::KEY]);
        unlink($this->dataDir . '/keys/settings.key');

        $settings = $this->call('GET', '/api/settings')['json'];

        self::assertFalse($settings['wifi']['configured']);
        self::assertStringContainsString('Enter the signing keys again', (string) $settings['notice']);
    }

    public function testTheConfigCardShowsThePlanBeforeAnyonePays(): void
    {
        $this->call('POST', '/api/terminals', ['name' => 'Hub', 'serialNumber' => 'WS-1', 'ecrMode' => 4, 'merchantId' => '10001', 'terminalId' => '20002']);
        $this->call('POST', '/api/terminals', ['name' => 'Cable', 'serialNumber' => 'USB-1', 'ecrMode' => 1]);

        $web = $this->call('GET', '/api/terminals/WS-1/config')['json'];
        self::assertSame('HTTPS Web Service (JSON HMAC)', $web['transportLabel']);
        self::assertSame('SIT · merchant 10001 · terminal 20002', $web['connectionSummary']);
        self::assertSame('Web Service secure hash key', $web['keyLabel']);
        self::assertSame('not configured — set in Settings', $web['keyStatus']);
        self::assertFalse($web['isReady']);
        self::assertContains('Web Service secure hash key is not configured', $web['issues']);

        $this->call('PUT', '/api/settings/keys/webService', ['value' => str_repeat('b', 64)]);
        $ready = $this->call('GET', '/api/terminals/WS-1/config?announce=true')['json'];
        self::assertTrue($ready['isReady']);
        self::assertSame('configured', $ready['keyStatus']);
        self::assertStringContainsString('[Web Service] selected WS-1 (Web Service)', $this->log());

        $usb = $this->call('GET', '/api/terminals/USB-1/config')['json'];
        self::assertFalse($usb['isSupported']);
        self::assertContains('USB Cable is not supported in this sample', $usb['issues']);
        self::assertSame(404, $this->call('GET', '/api/terminals/NOPE/config')['status']);
    }

    public function testMetaCarriesTheSdkFlagsTheFormLayoutsAndTheLimits(): void
    {
        $meta = $this->call('GET', '/api/meta')['json'];

        self::assertSame(['Sale', 'Void', 'Refund', 'Inquiry'], array_column($meta['operations'], 'value'));
        [$sale, $void, $refund, $inquiry] = $meta['operations'];
        self::assertTrue($sale['movesMoney']);
        self::assertTrue($sale['layouts']['00']['merchantReference']);
        self::assertSame("Uses the original transaction's amount", $void['amountHelp']);
        self::assertTrue($void['layouts']['10']['originalTerminal']);
        self::assertFalse($void['layouts']['00']['originalTerminal']);
        self::assertTrue($refund['layouts']['00']['originalDate']);
        self::assertSame('Look up transaction', $inquiry['startButtonText']);
        self::assertTrue($inquiry['layouts']['01']['originalReference']);
        self::assertFalse($inquiry['layouts']['01']['receiptNumber'], 'the receipt number gives way to the reference');
        self::assertFalse($inquiry['layouts']['01']['originalDate']);

        self::assertSame([1, 2, 3, 4], array_column($meta['modes'], 'value'));
        self::assertSame([false, true, false, true], array_column($meta['modes'], 'supported'));
        self::assertSame(['SIT', 'UAT', 'PROD'], $meta['environments']);
        self::assertSame(['min' => '0.010', 'max' => '100000.000', 'fractionDigits' => 3, 'maxDigits' => 9], $meta['amount']);
        self::assertSame(32, $meta['merchantReferenceMaxLength']);
        self::assertSame(6, $meta['receiptNumberMaxLength']);
        self::assertSame(9100, $meta['defaultPort']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $meta['today']);
    }
}
