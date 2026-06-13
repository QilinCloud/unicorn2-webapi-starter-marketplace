<?php

require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/ApiWebSecurity.php';

$method = $argv[1] ?? 'getCapabilities';
$url = $argv[2] ?? '';
$config = Config::load(__DIR__ . '/../config.php');
$failureMode = getenv('APIWEB_FAILURE_MODE');
$envelope = [
    'Source' => 'unicorn2',
    'Method' => $method,
    'LicenceKey' => 'local-dev',
    'ShopId' => 1,
    'CreatedAtUtc' => gmdate('c'),
    'Reference' => null,
    'Objects' => [$method === 'getOrders' ? ['State' => 'editable'] : ['ShopId' => 'demo-shop-id']],
];

if ($failureMode !== false && $failureMode !== '') {
    $envelope['Debug'] = ['FailureMode' => $failureMode];
}

$body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($body === false) {
    fwrite(STDERR, "Could not encode request body.\n");
    exit(1);
}

$timestamp = (string)time();
$nonce = bin2hex(random_bytes(12));
$bodyHash = hash('sha256', $body);
$signature = ApiWebSecurity::signature($config, $method, $timestamp, $nonce, $bodyHash);
$headers = [
    'Content-Type: application/json',
    'X-Unicorn-Signature-Version: ' . ($config['apiweb']['signature_version'] ?? '2026-06-13.hmac-sha256'),
    'X-Unicorn-Api-Method: ' . $method,
    'X-Unicorn-Timestamp: ' . $timestamp,
    'X-Unicorn-Nonce: ' . $nonce,
    'X-Unicorn-Body-Sha256: ' . $bodyHash,
    'X-Unicorn-Signature: ' . $signature,
];

if ($url === '') {
    echo "Body:\n" . $body . "\n\nHeaders:\n" . implode("\n", $headers) . "\n";
    exit(0);
}

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 20,
    ],
]);

$response = file_get_contents($url, false, $context);
echo $response === false ? "Request failed.\n" : $response . "\n";
