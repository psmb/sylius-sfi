<?php

declare(strict_types=1);

function sendCloudpaymentsNotification(string $type, array $payload, string $secretKey): array
{
    $body = http_build_query($payload, '', '&');
    $callbackUrl = rtrim(getenv('CLOUDPAYMENTS_MOCK_CALLBACK_URL') ?: 'http://127.0.0.1:8000', '/');
    $curl = curl_init(sprintf('%s/payment/cloudpayments/notify/%s', $callbackUrl, $type));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'Content-HMAC: ' . base64_encode(hash_hmac('sha256', $body, $secretKey, true)),
    ]);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $responseBody = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($responseBody === false) {
        throw new RuntimeException('Notification callback failed: ' . $error);
    }

    return [
        'statusCode' => $statusCode,
        'body' => json_decode($responseBody, true),
    ];
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$secretKey = $_SERVER['PHP_AUTH_PW'] ?? 'sk_verify';
$stateDirectory = sys_get_temp_dir() . '/cloudpayments-sbp-mock';
if (!is_dir($stateDirectory)) {
    mkdir($stateDirectory, 0777, true);
}

if ($path === '/payments/qr/sbp/link' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['Success' => false, 'Message' => 'Invalid JSON']);

        return;
    }

    $transactionId = (string) abs(crc32(($payload['InvoiceId'] ?? '') . ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')));
    $notificationPayload = [
        'TransactionId' => $transactionId,
        'Amount' => $payload['Amount'],
        'Currency' => $payload['Currency'],
        'PaymentAmount' => $payload['Amount'],
        'PaymentCurrency' => $payload['Currency'],
        'DateTime' => gmdate('Y-m-d H:i:s'),
        'TestMode' => !empty($payload['IsTest']) ? '1' : '0',
        'Status' => 'Completed',
        'OperationType' => 'Payment',
        'InvoiceId' => $payload['InvoiceId'],
        'AccountId' => $payload['AccountId'] ?? '',
        'Email' => $payload['Email'] ?? '',
        'PaymentMethod' => 'Sbp',
    ];

    $checkResponse = sendCloudpaymentsNotification('check', $notificationPayload, $secretKey);
    if ($checkResponse['statusCode'] !== 200 || ($checkResponse['body']['code'] ?? null) !== 0) {
        header('Content-Type: application/json');
        echo json_encode([
            'Success' => false,
            'Message' => 'Check notification rejected the payment.',
        ]);

        return;
    }

    $state = [
        'notificationPayload' => $notificationPayload,
        'secretKey' => $secretKey,
        'successRedirectUrl' => $payload['SuccessRedirectUrl'] ?? null,
    ];
    file_put_contents($stateDirectory . '/' . hash('sha256', $payload['InvoiceId']) . '.json', json_encode($state));

    header('Content-Type: application/json');
    echo json_encode([
        'Model' => [
            'QrUrl' => 'http://127.0.0.1:18080/mock-bank/sbp-pay?invoice=' . rawurlencode($payload['InvoiceId']),
            'TransactionId' => $transactionId,
            'MerchantOrderId' => $payload['InvoiceId'],
            'ProviderQrId' => 'LOCAL_' . $transactionId,
            'Amount' => $payload['Amount'],
            'Message' => 'Created',
            'IsTest' => !empty($payload['IsTest']),
        ],
        'Success' => true,
        'Message' => null,
    ]);

    return;
}

if ($path === '/mock-bank/sbp-pay') {
    $invoiceId = (string) ($_GET['invoice'] ?? '');
    $stateFile = $stateDirectory . '/' . hash('sha256', $invoiceId) . '.json';
    $state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    if (!is_array($state)) {
        http_response_code(404);
        echo 'Unknown payment';

        return;
    }

    $payResponse = sendCloudpaymentsNotification('pay', $state['notificationPayload'], $state['secretKey']);
    if ($payResponse['statusCode'] !== 200 || ($payResponse['body']['code'] ?? null) !== 0) {
        http_response_code(502);
        echo 'Pay notification failed';

        return;
    }

    if ($state['successRedirectUrl']) {
        header('Location: ' . $state['successRedirectUrl'], true, 302);

        return;
    }

    echo 'Payment completed';

    return;
}

http_response_code(404);
echo 'Not found';
