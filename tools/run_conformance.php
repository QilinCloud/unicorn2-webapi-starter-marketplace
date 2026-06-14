<?php

require_once __DIR__ . '/../classes/Config.php';
require_once __DIR__ . '/../classes/ApiWebSecurity.php';

/**
 * Minimal ApiWeb conformance runner for third-party connector development.
 *
 * The runner acts like Unicorn 2 from the outside: it signs requests, sends
 * them to api.php and validates the response envelope, supported methods,
 * object-level errors and selected negative paths. It intentionally does not
 * require access to the internal Unicorn 2 C# codebase.
 */
final class ApiWebConformanceRunner
{
    /** @var array<string,mixed> */
    private array $config;

    private string $url;

    /** @var array<int,string> */
    private array $failures = [];

    /**
     * Creates a conformance runner.
     *
     * @param array<string,mixed> $config ApiWeb configuration.
     * @param string $url Connector endpoint URL.
     */
    public function __construct(array $config, string $url)
    {
        $this->config = $config;
        $this->url = $url;
    }

    /**
     * Runs all contract checks and returns a process exit code.
     *
     * @return int 0 when all checks pass, otherwise 1.
     */
    public function run(): int
    {
        $this->checkCapabilities();
        $this->checkValidateCredentials();
        $this->checkOrders();
        $this->checkObjectUpdates();
        $this->checkNegativeResponses();

        if (count($this->failures) === 0) {
            echo "ApiWeb conformance: PASS\n";
            return 0;
        }

        echo "ApiWeb conformance: FAIL\n";
        foreach ($this->failures as $failure) {
            echo "- " . $failure . "\n";
        }

        return 1;
    }

    /**
     * Verifies getCapabilities shape and required minimal methods.
     *
     * @return void
     */
    private function checkCapabilities(): void
    {
        $response = $this->request('getCapabilities');
        $this->assertEnvelope('getCapabilities', $response);

        $item = $response['Results'][0]['Item'] ?? null;
        if (!is_array($item)) {
            $this->fail('getCapabilities must return Results[0].Item.');
            return;
        }

        $requiredMethods = [
            'validateCredentials',
            'getCapabilities',
            'getOrders',
            'setOrderSend',
            'setStock',
            'setPrice',
            'setProcessingTime',
        ];
        $supportedMethods = $item['SupportedMethods'] ?? [];
        foreach ($requiredMethods as $method) {
            if (!is_array($supportedMethods) || !in_array($method, $supportedMethods, true)) {
                $this->fail('getCapabilities must list SupportedMethods entry ' . $method . '.');
            }
        }

        $features = $item['Features'] ?? [];
        if (is_array($features) && array_key_exists('DetectStatus', $features) && $features['DetectStatus'] !== false) {
            $this->fail('Starter/minimal connectors must not advertise DetectStatus unless CheckStatus is implemented.');
        }

        $this->pass('getCapabilities');
    }

    /**
     * Verifies positive credential validation.
     *
     * @return void
     */
    private function checkValidateCredentials(): void
    {
        $response = $this->request('validateCredentials');
        $this->assertEnvelope('validateCredentials', $response);

        $item = $response['Results'][0]['Item'] ?? null;
        if (!is_array($item) || !array_key_exists('Valid', $item)) {
            $this->fail('validateCredentials must return Results[0].Item.Valid.');
            return;
        }

        if ($item['Valid'] !== true) {
            $this->fail('validateCredentials happy path should return Valid=true with demo/mock settings.');
        }

        $this->pass('validateCredentials');
    }

    /**
     * Verifies order download response shape.
     *
     * @return void
     */
    private function checkOrders(): void
    {
        $response = $this->request('getOrders');
        $this->assertEnvelope('getOrders', $response);

        if (!isset($response['Results'][0]['Collection']) || !is_array($response['Results'][0]['Collection'])) {
            $this->fail('getOrders must return Results[0].Collection, even when it is empty.');
            return;
        }

        $this->pass('getOrders');
    }

    /**
     * Verifies all minimal object update methods.
     *
     * @return void
     */
    private function checkObjectUpdates(): void
    {
        foreach (['setStock', 'setPrice', 'setProcessingTime', 'setOrderSend'] as $method) {
            $response = $this->request($method);
            $this->assertEnvelope($method, $response);

            $item = $response['Results'][0]['Item'] ?? null;
            $errors = $response['Results'][0]['Errors'] ?? [];
            if (is_array($errors) && count($errors) > 0) {
                $this->fail($method . ' happy path returned object errors: ' . json_encode($errors));
                continue;
            }

            if (!is_array($item) || ($item['Success'] ?? null) !== true) {
                $this->fail($method . ' must return Results[0].Item.Success=true on happy path.');
                continue;
            }

            $this->pass($method);
        }
    }

    /**
     * Verifies common failure modes and error placement.
     *
     * @return void
     */
    private function checkNegativeResponses(): void
    {
        $invalidCredentials = $this->request('validateCredentials', 'invalid_credentials');
        $item = $invalidCredentials['Results'][0]['Item'] ?? null;
        if (!is_array($item) || ($item['Valid'] ?? null) !== false) {
            $this->fail('validateCredentials invalid_credentials must return Item.Valid=false.');
        } else {
            $this->pass('validateCredentials invalid_credentials');
        }

        $quota = $this->request('getOrders', 'getOrders:quota');
        $this->assertObjectErrorCode('getOrders quota', $quota, 429);

        $apiDown = $this->request('getOrders', 'getOrders:api_down');
        $this->assertObjectErrorCode('getOrders api_down', $apiDown, 503);

        $unknown = $this->request('getOrders', 'getOrders:unknown');
        $this->assertObjectErrorCode('getOrders unknown', $unknown, 999);

        $missingId = $this->request('setStock', null, [[]]);
        $this->assertObjectErrorCode('setStock missing identifier', $missingId, 422);

        $badSignature = $this->request('getCapabilities', null, null, 'wrong-secret');
        $error = $badSignature['Error'] ?? null;
        if (!is_array($error) || (int)($error['Code'] ?? 0) !== 401) {
            $this->fail('Bad ApiWeb signature must return top-level Error.Code=401.');
        } else {
            $this->pass('bad signature');
        }
    }

    /**
     * Sends a signed ApiWeb request.
     *
     * @param string $method ApiWeb method.
     * @param string|null $failureMode Optional demo failure mode.
     * @param array<int,array<string,mixed>>|null $objects Optional request objects.
     * @param string|null $secretOverride Optional secret for negative auth tests.
     * @return array<string,mixed> Decoded response.
     */
    private function request(string $method, ?string $failureMode = null, ?array $objects = null, ?string $secretOverride = null): array
    {
        $envelope = [
            'Source' => 'unicorn2-conformance',
            'Method' => $method,
            'LicenceKey' => 'local-dev',
            'ShopId' => 1,
            'CreatedAtUtc' => gmdate('c'),
            'Reference' => 'conformance-' . bin2hex(random_bytes(4)),
            'Objects' => $objects ?? [$method === 'getOrders' ? ['State' => 'editable'] : $this->defaultObject($method)],
        ];

        if ($failureMode !== null) {
            $envelope['Debug'] = ['FailureMode' => $failureMode];
        }

        $body = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            $this->fail($method . ': could not encode request body.');
            return [];
        }

        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $bodyHash = ApiWebSecurity::sha256Base64($body);

        $config = $this->config;
        if ($secretOverride !== null) {
            $config['apiweb']['secret'] = $secretOverride;
        }

        $signature = ApiWebSecurity::signature($config, $method, 'POST', $timestamp, $nonce, $bodyHash);
        $headers = [
            'Content-Type: application/json',
            'X-Unicorn-Signature-Version: ' . ($this->config['apiweb']['signature_version'] ?? '2026-06-13.hmac-sha256'),
            'X-Unicorn-Api-Method: ' . $method,
            'X-Unicorn-Timestamp: ' . $timestamp,
            'X-Unicorn-Nonce: ' . $nonce,
            'X-Unicorn-Body-Sha256: ' . $bodyHash,
            'X-Unicorn-Signature: ' . $signature,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);

        $raw = @file_get_contents($this->url, false, $context);
        if (!is_string($raw)) {
            $this->fail($method . ': request failed against ' . $this->url . '.');
            return [];
        }

        $this->assertSignedResponse($method, $raw, $http_response_header ?? []);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->fail($method . ': response is not JSON: ' . substr($raw, 0, 300));
            return [];
        }

        return $decoded;
    }

    /**
     * Validates ApiWeb response signature headers.
     *
     * @param string $method ApiWeb method that was called.
     * @param string $rawBody Raw response body.
     * @param array<int,string> $rawHeaders Response headers from PHP stream wrapper.
     * @return void
     */
    private function assertSignedResponse(string $method, string $rawBody, array $rawHeaders): void
    {
        $headers = $this->parseHeaders($rawHeaders);
        $required = [
            'x-unicorn-signature-version',
            'x-unicorn-api-method',
            'x-unicorn-timestamp',
            'x-unicorn-nonce',
            'x-unicorn-body-sha256',
            'x-unicorn-signature',
        ];

        foreach ($required as $header) {
            if (($headers[$header] ?? '') === '') {
                $this->fail($method . ' response is missing header ' . $header . '.');
                return;
            }
        }

        if (($headers['x-unicorn-signature-version'] ?? '') !== ($this->config['apiweb']['signature_version'] ?? '2026-06-13.hmac-sha256')) {
            $this->fail($method . ' response signature version is invalid.');
            return;
        }

        if (($headers['x-unicorn-api-method'] ?? '') !== $method) {
            $this->fail($method . ' response X-Unicorn-Api-Method must be the original request method.');
            return;
        }

        $expectedBodyHash = ApiWebSecurity::sha256Base64($rawBody);
        if (!hash_equals($expectedBodyHash, $headers['x-unicorn-body-sha256'])) {
            $this->fail($method . ' response body hash is invalid. Sign the exact raw response body sent to Unicorn.');
            return;
        }

        $expectedSignature = ApiWebSecurity::signature(
            $this->config,
            $method,
            'RESPONSE',
            $headers['x-unicorn-timestamp'],
            $headers['x-unicorn-nonce'],
            $headers['x-unicorn-body-sha256']
        );

        if (!hash_equals($expectedSignature, $headers['x-unicorn-signature'])) {
            $this->fail($method . ' response signature is invalid.');
        }
    }

    /**
     * Parses response header lines into lowercase header names.
     *
     * @param array<int,string> $rawHeaders Response headers from PHP stream wrapper.
     * @return array<string,string>
     */
    private function parseHeaders(array $rawHeaders): array
    {
        $headers = [];
        foreach ($rawHeaders as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $position)));
            $headers[$name] = trim(substr($line, $position + 1));
        }

        return $headers;
    }

    /**
     * Returns a deterministic object for update methods.
     *
     * @param string $method ApiWeb method.
     * @return array<string,mixed>
     */
    private function defaultObject(string $method): array
    {
        $object = ['ShopId' => 'demo-shop-id'];
        if ($method === 'setStock') {
            $object['Quantity'] = 7;
        }
        if ($method === 'setPrice') {
            $object['PriceGross'] = 19.99;
            $object['Currency'] = 'EUR';
        }
        if ($method === 'setProcessingTime') {
            $object['ProcessingTimeDays'] = 2;
        }
        if ($method === 'setOrderSend') {
            $object['TrackingNumber'] = 'DEMO-TRACK-1';
            $object['Carrier'] = 'DHL';
        }

        return $object;
    }

    /**
     * Validates the common ApiWeb envelope.
     *
     * @param string $method ApiWeb method.
     * @param array<string,mixed> $response Decoded response.
     * @return void
     */
    private function assertEnvelope(string $method, array $response): void
    {
        foreach (['Results', 'Error', 'Key', 'Stop'] as $field) {
            if (!array_key_exists($field, $response)) {
                $this->fail($method . ' response is missing envelope field ' . $field . '.');
            }
        }

        if (($response['Error'] ?? null) !== null) {
            $this->fail($method . ' returned top-level Error: ' . json_encode($response['Error']));
        }

        if (!isset($response['Results']) || !is_array($response['Results'])) {
            $this->fail($method . ' response Results must be an array.');
        }
    }

    /**
     * Asserts an object-level error code.
     *
     * @param string $name Test name.
     * @param array<string,mixed> $response Decoded response.
     * @param int $expectedCode Expected error code.
     * @return void
     */
    private function assertObjectErrorCode(string $name, array $response, int $expectedCode): void
    {
        $errors = $response['Results'][0]['Errors'] ?? null;
        if (!is_array($errors) || count($errors) === 0) {
            $this->fail($name . ' must return an object-level error.');
            return;
        }

        if ((int)($errors[0]['Code'] ?? 0) !== $expectedCode) {
            $this->fail($name . ' must return error code ' . $expectedCode . ', got ' . json_encode($errors[0]));
            return;
        }

        if (trim((string)($errors[0]['Message'] ?? '')) === '') {
            $this->fail($name . ' must return a user-facing error message.');
            return;
        }

        $this->pass($name);
    }

    /**
     * Prints a passing check.
     *
     * @param string $name Check name.
     * @return void
     */
    private function pass(string $name): void
    {
        echo "PASS " . $name . "\n";
    }

    /**
     * Records a failure.
     *
     * @param string $message Failure message.
     * @return void
     */
    private function fail(string $message): void
    {
        $this->failures[] = $message;
        echo "FAIL " . $message . "\n";
    }
}

$url = $argv[1] ?? 'http://127.0.0.1:18080/api.php';
$config = Config::load(__DIR__ . '/../config.php');
$runner = new ApiWebConformanceRunner($config, $url);
exit($runner->run());
