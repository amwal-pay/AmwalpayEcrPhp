<?php

declare(strict_types=1);

namespace AmwalPay\Ecr\Tests\Example;

/** The web sample's SecurityTests: headers, the /api guard, and the page's rendering rules. */
final class SecurityTest extends WebExampleTestCase
{
    public function testEveryResponseCarriesTheSecurityHeadersAndApiIsNeverCached(): void
    {
        $page = $this->call('GET', '/');
        $api = $this->call('GET', '/api/meta');

        self::assertSame(200, $page['status']);
        self::assertStringContainsString('<title>Amwal ECR Example</title>', $page['body']);
        foreach ([$page, $api] as $response) {
            self::assertSame('nosniff', $response['headers']['x-content-type-options']);
            self::assertSame('DENY', $response['headers']['x-frame-options']);
            self::assertSame('no-referrer', $response['headers']['referrer-policy']);
            self::assertSame("default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'", $response['headers']['content-security-policy']);
        }
        self::assertSame('no-store', $api['headers']['cache-control']);
        self::assertSame(200, $this->call('GET', '/js/transaction.js')['status']);
        self::assertSame(404, $this->call('GET', '/../composer.json')['status']);
    }

    public function testCrossOriginAndNonJsonCallsAreRefusedAndNothingReachesTheTerminal(): void
    {
        $this->registerWifi();
        $form = json_encode(['terminalSerial' => self::SERIAL, 'type' => 'Sale', 'amountDigits' => '1000']);

        self::assertSame(415, $this->raw('POST', '/api/transactions', (string) $form, ['Content-Type' => 'text/plain'])['status']);
        self::assertSame(415, $this->raw('POST', '/api/transactions', 'terminalSerial=x', ['Content-Type' => 'application/x-www-form-urlencoded'])['status']);
        $foreign = $this->raw('POST', '/api/transactions', (string) $form, ['Content-Type' => 'application/json', 'Origin' => 'http://evil.example']);
        self::assertSame(403, $foreign['status']);
        self::assertSame(['error' => 'Cross-origin requests are not accepted'], $foreign['json']);
        self::assertSame(403, $this->raw('POST', '/api/transactions', (string) $form, ['Content-Type' => 'application/json', 'Sec-Fetch-Site' => 'same-site'])['status']);
        self::assertSame(400, $this->raw('GET', '/', null, ['Host' => 'evil.example'])['status'], 'DNS rebinding');
        self::assertSame(400, $this->raw('POST', '/api/transactions', '{not json', ['Content-Type' => 'application/json'])['status']);
        self::assertSame(413, $this->raw('POST', '/api/terminals', str_repeat(' ', 70_000) . '{}', ['Content-Type' => 'application/json'])['status']);

        self::assertSame([], $this->call('GET', '/api/transactions')['json']['transactions']);
        self::assertStringNotContainsString('"messageType":"SALE"', $this->terminals[0]->output());
    }

    public function testAnUnknownApiPathIsAJson404(): void
    {
        $response = $this->call('GET', '/api/nothing');

        self::assertSame(404, $response['status']);
        self::assertSame('No such endpoint', $response['json']['error']);
    }

    public function testThePageHasNoInlineScriptStyleHandlersOrMarkupInjection(): void
    {
        $public = \dirname(__DIR__, 2) . '/examples/web/public';
        foreach (array_merge(glob($public . '/*.{html,js,css}', \GLOB_BRACE) ?: [], glob($public . '/js/*.js') ?: []) as $file) {
            $text = (string) file_get_contents($file);
            foreach (['innerHTML', 'eval(', 'onclick="', 'style="', '<script>'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $text, basename($file) . ' contains ' . $forbidden);
            }
            self::assertSame(0, preg_match('#https?://(?!www\.w3\.org/2000/svg)#', $text), basename($file) . ' loads something from the network');
        }
    }
}
