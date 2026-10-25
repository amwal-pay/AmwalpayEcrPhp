<?php

declare(strict_types=1);

/**
 * A stand-in for the Amwal Hub, as a router for PHP's built-in server:
 *
 *   php -S 127.0.0.1:<port> tests/Support/fake-hub.php
 *
 * The behaviour comes from the `mode` query parameter, which the tests'
 * rewriting transport adds. Each request body is appended to the file named by
 * the AMWAL_FAKE_HUB_RECORD environment variable.
 */

$mode = $_GET['mode'] ?? 'approve';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = (string) file_get_contents('php://input');
$record = getenv('AMWAL_FAKE_HUB_RECORD');
if ($record !== false && $record !== '') {
    file_put_contents($record, json_encode(['path' => $path, 'contentType' => $_SERVER['CONTENT_TYPE'] ?? '', 'body' => $body]) . "\n", FILE_APPEND | LOCK_EX);
}
$request = json_decode($body, true) ?: [];
$reference = $request['merchantReference'] ?? '';

$send = static function (int $status, string $text, string $reason = ''): void {
    header(sprintf('HTTP/1.1 %d %s', $status, $reason !== '' ? $reason : 'Status'), true, $status);
    header('Content-Type: application/json');
    echo $text;
};

switch ($mode) {
    case 'approve':
        if ($path === '/Ecr/Inquiry') {
            $send(200, json_encode(['success' => true, 'responseCode' => '00', 'message' => 'Transaction found', 'data' => [
                'merchantReference' => $request['originalMerchantReference'] ?? '', 'stan' => '000215', 'status' => 'Approved',
                'amount' => '1.234', 'rrn' => '623807390109', 'transactionTime' => '2026-10-25T10:00:00', 'canVoid' => true,
            ], 'errorList' => []]));
            break;
        }
        $send(200, json_encode(['success' => true, 'responseCode' => '00', 'message' => 'Approved', 'data' => [
            'merchantReference' => $reference, 'amount' => '1.234', 'authorizeAmount' => '1.234', 'rrn' => '623807390109',
            'authCode' => '713984', 'cardMask' => '543173xxxx5785',
        ], 'errorList' => []]));
        break;
    case 'decline':
        $send(200, '{"success":false,"responseCode":"51","message":"Declined","data":null,"errorList":["Insufficient funds"]}');
        break;
    case 'hub-timeout':
        $send(200, '{"success":false,"responseCode":"NO_RESPONSE","message":"timeout","nextStep":"INQUIRE_BY_MERCHANT_REFERENCE"}');
        break;
    case 'not-found':
        $send(200, '{"success":false,"responseCode":"25","message":"Transaction not found","data":null}');
        break;
    case 'error-500':
        $send(500, '', 'Internal Server Error');
        break;
    case 'error-503-explained':
        $send(503, '{"success":false,"responseCode":"503","message":"Terminal did not respond"}', 'Service Unavailable');
        break;
    case 'unauthorized':
        $send(401, '{"success":false,"responseCode":"401","message":"Invalid signature","errorList":["secureHashValue mismatch"]}', 'Unauthorized');
        break;
    case 'empty':
        $send(200, '');
        break;
    case 'html':
        header('Content-Type: text/html');
        echo '<html>bad gateway</html>';
        break;
    case 'slow':
        sleep(3);
        $send(200, '{"success":true,"responseCode":"00"}');
        break;
    default:
        $send(400, '{"success":false,"message":"unknown mode"}');
}
