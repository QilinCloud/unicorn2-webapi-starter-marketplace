<?php

require_once __DIR__ . '/classes/Config.php';
require_once __DIR__ . '/classes/ApiWebSecurity.php';
require_once __DIR__ . '/classes/ApiWebResponse.php';
require_once __DIR__ . '/classes/MarketplaceClient.php';
require_once __DIR__ . '/classes/ApiWebEndpoint.php';

$config = Config::load(__DIR__ . '/config.php');
$body = file_get_contents('php://input') ?: '';
$headers = function_exists('getallheaders') ? getallheaders() : [];
$decodedForResponseSigning = json_decode($body, true);
$responseApiMethod = is_array($decodedForResponseSigning) && isset($decodedForResponseSigning['Method'])
    ? (string)$decodedForResponseSigning['Method']
    : '';
if ($responseApiMethod === '') {
    foreach ($headers as $name => $value) {
        if (strcasecmp((string)$name, 'X-Unicorn-Api-Method') === 0) {
            $responseApiMethod = (string)$value;
            break;
        }
    }
}

try {
    $endpoint = new ApiWebEndpoint($config, new MarketplaceClient($config));
    [$statusCode, $answer] = $endpoint->handle($body, $headers);
} catch (Throwable $exception) {
    $statusCode = 200;
    $answer = ApiWebResponse::protocolError(
        999,
        'Unexpected connector error. Check endpoint logs, method and payload. ' . $exception->getMessage(),
        true
    );
}

$responseBody = json_encode($answer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($responseBody === false) {
    $responseBody = '{"Results":[],"Error":{"Code":999,"Message":"Could not encode ApiWeb response."},"Key":"","Stop":true}';
}

http_response_code($statusCode);
header('Content-Type: application/json; charset=utf-8');
ApiWebSecurity::emitResponseHeaders($config, $responseBody, $responseApiMethod);
echo $responseBody;
