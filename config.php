<?php

/**
 * Starter configuration for a third-party Unicorn 2 ApiWeb marketplace connector.
 *
 * Keep secrets in protected hosting settings or environment variables. The
 * fallback values below are only for local smoke tests.
 */
function apiweb_starter_env(string $name, string $fallback = ''): string
{
    $value = getenv($name);
    return $value === false ? $fallback : $value;
}

/**
 * Reads a boolean environment value.
 *
 * @param string $name Environment variable name.
 * @param bool $fallback Fallback value.
 * @return bool Boolean value.
 */
function apiweb_starter_bool(string $name, bool $fallback = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $fallback;
    }

    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

return [
    'apiweb' => [
        'secret' => apiweb_starter_env('APIWEB_TEST_KEY', 'local-dev-api-key-2026'),
        'signature_version' => '2026-06-13.hmac-sha256',
        'timestamp_tolerance_seconds' => 300,
    ],
    'connector' => [
        'name' => apiweb_starter_env('CONNECTOR_NAME', 'Generic Marketplace ApiWeb Connector'),
        'version' => '1.0.0',
        'mode' => apiweb_starter_env('MARKETPLACE_MODE', 'demo'),
        'log_level' => apiweb_starter_env('APIWEB_LOG_LEVEL', 'info'),
    ],
    'marketplace' => [
        'base_url' => rtrim(apiweb_starter_env('MARKETPLACE_BASE_URL', ''), '/'),
        'client_id' => apiweb_starter_env('MARKETPLACE_CLIENT_ID', ''),
        'client_secret' => apiweb_starter_env('MARKETPLACE_CLIENT_SECRET', ''),
        'endpoints' => [
            'validateCredentials' => apiweb_starter_env('MARKETPLACE_ENDPOINT_VALIDATE', ''),
            'getOrders' => apiweb_starter_env('MARKETPLACE_ENDPOINT_ORDERS', ''),
            'setOrderSend' => apiweb_starter_env('MARKETPLACE_ENDPOINT_SHIPMENT', ''),
            'setStock' => apiweb_starter_env('MARKETPLACE_ENDPOINT_STOCK', ''),
            'setPrice' => apiweb_starter_env('MARKETPLACE_ENDPOINT_PRICE', ''),
            'setProcessingTime' => apiweb_starter_env('MARKETPLACE_ENDPOINT_PROCESSING_TIME', ''),
        ],
    ],
    'debug' => [
        'failure_mode' => apiweb_starter_env('APIWEB_FAILURE_MODE', ''),
        'allow_request_failure_mode' => apiweb_starter_bool('APIWEB_ALLOW_REQUEST_FAILURE_MODE', false),
    ],
];
