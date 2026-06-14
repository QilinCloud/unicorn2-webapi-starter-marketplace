<?php

/**
 * Local mock marketplace for ApiWeb connector development.
 *
 * Start it with:
 * php -S 127.0.0.1:18081 tools/mock_marketplace.php
 *
 * The connector can then be started in real mode against this mock by setting:
 * MARKETPLACE_BASE_URL=http://127.0.0.1:18081
 * MARKETPLACE_ENDPOINT_VALIDATE=/auth/check
 * MARKETPLACE_ENDPOINT_ORDERS=/orders
 * MARKETPLACE_ENDPOINT_SHIPMENT=/shipments
 * MARKETPLACE_ENDPOINT_STOCK=/stock
 * MARKETPLACE_ENDPOINT_PRICE=/prices
 * MARKETPLACE_ENDPOINT_PROCESSING_TIME=/delivery-times
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$failure = $_GET['failure'] ?? ($_SERVER['HTTP_X_MOCK_FAILURE'] ?? '');

if ($failure !== '') {
    send_failure((string)$failure);
    return;
}

if ($path === '/auth/check' && $method === 'GET') {
    send_json(200, [
        'valid' => true,
        'sellerId' => 'mock-seller-1',
        'message' => 'Mock credentials are valid.',
    ]);
    return;
}

if ($path === '/orders' && $method === 'GET') {
    send_json(200, [
        'orders' => [
            [
                'id' => 'mock-order-1001',
                'number' => 'MOCK-1001',
                'currency' => 'EUR',
                'total' => 42.50,
                'created_at' => gmdate('c'),
                'buyer' => [
                    'email' => 'buyer@example.invalid',
                    'firstName' => 'Mock',
                    'lastName' => 'Buyer',
                ],
                'items' => [
                    [
                        'id' => 'mock-line-1',
                        'sku' => 'MOCK-SKU-1',
                        'name' => 'Mock article',
                        'quantity' => 1,
                        'price' => 42.50,
                    ],
                ],
            ],
        ],
    ]);
    return;
}

if (in_array($path, ['/shipments', '/stock', '/prices', '/delivery-times'], true) && $method === 'POST') {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    send_json(200, [
        'accepted' => true,
        'path' => $path,
        'received' => is_array($payload) ? $payload : [],
    ]);
    return;
}

send_json(404, [
    'code' => 'not_found',
    'message' => 'Mock marketplace endpoint not found: ' . $method . ' ' . $path,
]);

/**
 * Sends a configured failure response.
 *
 * @param string $failure Failure code.
 * @return void
 */
function send_failure(string $failure): void
{
    switch ($failure) {
        case 'invalid_credentials':
        case 'auth':
            send_json(401, [
                'code' => 'credentials_invalid',
                'message' => 'Mock marketplace rejected credentials.',
            ]);
            return;

        case 'forbidden':
            send_json(403, [
                'code' => 'permission_missing',
                'message' => 'Mock marketplace credentials do not have the required scope.',
            ]);
            return;

        case 'quota':
            send_json(429, [
                'code' => 'quota_exceeded',
                'message' => 'Mock marketplace request limit reached.',
            ]);
            return;

        case 'validation':
            send_json(400, [
                'code' => 'validation_error',
                'message' => 'Mock marketplace rejected one or more field values.',
                'invalidParams' => [
                    ['name' => 'ShopId', 'reason' => 'Unknown marketplace identifier.'],
                ],
            ]);
            return;

        case 'down':
        case 'api_down':
            send_json(503, [
                'code' => 'service_unavailable',
                'message' => 'Mock marketplace API is temporarily unavailable.',
            ]);
            return;

        default:
            send_json(500, [
                'code' => 'unknown_error',
                'message' => 'Mock marketplace unknown failure: ' . $failure,
            ]);
            return;
    }
}

/**
 * Sends a JSON response.
 *
 * @param int $status HTTP status.
 * @param array<string,mixed> $payload JSON payload.
 * @return void
 */
function send_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
