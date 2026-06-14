<?php

/**
 * Marketplace adapter template.
 *
 * Replace the demo branches in this class with calls to the real marketplace
 * API. Keep the public ApiWeb classes unchanged unless the ApiWeb protocol
 * itself changes.
 */
final class MarketplaceClient
{
    /** @var array<string,mixed> */
    private array $config;

    private ?string $failureModeOverride = null;

    /**
     * Creates a marketplace adapter.
     *
     * @param array<string,mixed> $config Connector configuration.
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Sets a request-local failure mode for signed demo conformance tests.
     *
     * @param string $failureMode Failure mode name.
     * @return void
     */
    public function setFailureMode(string $failureMode): void
    {
        $this->failureModeOverride = $failureMode;
    }

    /**
     * Validates marketplace credentials.
     *
     * @return array<string,mixed> ApiWeb credential result item.
     */
    public function validateCredentials(): array
    {
        if ($this->failureMode() === 'invalid_credentials') {
            return [
                'Valid' => false,
                'Message' => 'Marketplace rejected the configured credentials or scopes. Check token, app installation and seller permissions.',
            ];
        }

        if ($this->mode() === 'demo') {
            return [
                'Valid' => true,
                'Message' => 'Demo credentials are valid. Switch MARKETPLACE_MODE to real after configuring marketplace credentials.',
            ];
        }

        $endpoint = $this->endpoint('validateCredentials');
        if ($endpoint === '') {
            return [
                'Valid' => false,
                'Message' => 'No marketplace credential validation endpoint is configured.',
            ];
        }

        $response = $this->request('GET', $endpoint);
        return [
            'Valid' => $response['ok'],
            'Message' => $response['ok']
                ? 'Marketplace credentials are valid.'
                : 'Marketplace credential validation failed. ' . $response['message'],
        ];
    }

    /**
     * Downloads marketplace orders for Unicorn.
     *
     * @param array<string,mixed> $filter Request filter object.
     * @return array<string,mixed> Result data with collection or errors.
     */
    public function getOrders(array $filter): array
    {
        $failure = $this->failureMode();
        if ($failure === 'getOrders:quota') {
            return ['errors' => [ApiWebResponse::error(429, 'Marketplace request limit reached for order download. Retry later and reduce request frequency.')]];
        }
        if ($failure === 'getOrders:api_down') {
            return ['errors' => [ApiWebResponse::error(503, 'Marketplace API is currently unreachable for order download. Retry later.')]];
        }
        if ($failure === 'getOrders:unknown') {
            return ['errors' => [ApiWebResponse::error(999, 'Unexpected marketplace error during order download. Check endpoint logs and marketplace response.')]];
        }
        if ($failure === 'getOrders:empty') {
            return ['collection' => []];
        }

        if ($this->mode() === 'demo') {
            return ['collection' => [$this->demoOrder()]];
        }

        $endpoint = $this->endpoint('getOrders');
        if ($endpoint === '') {
            return ['errors' => [ApiWebResponse::error(503, 'No marketplace order endpoint is configured.')]];
        }

        $response = $this->request('GET', $endpoint);
        if (!$response['ok']) {
            return ['errors' => [$this->mapHttpError($response, 'order download')]];
        }

        return ['collection' => $this->mapOrders($response['data'])];
    }

    /**
     * Sends shipment feedback to the marketplace.
     *
     * @param array<string,mixed> $object Shipment object from Unicorn.
     * @return array<string,mixed> Result item or errors.
     */
    public function setOrderSend(array $object): array
    {
        return $this->processObjectUpdate('setOrderSend', $object, 'shipment confirmation');
    }

    /**
     * Updates stock at the marketplace.
     *
     * @param array<string,mixed> $object Stock object from Unicorn.
     * @return array<string,mixed> Result item or errors.
     */
    public function setStock(array $object): array
    {
        return $this->processObjectUpdate('setStock', $object, 'stock update');
    }

    /**
     * Updates price at the marketplace.
     *
     * @param array<string,mixed> $object Price object from Unicorn.
     * @return array<string,mixed> Result item or errors.
     */
    public function setPrice(array $object): array
    {
        return $this->processObjectUpdate('setPrice', $object, 'price update');
    }

    /**
     * Updates delivery or processing time at the marketplace.
     *
     * @param array<string,mixed> $object Processing-time object from Unicorn.
     * @return array<string,mixed> Result item or errors.
     */
    public function setProcessingTime(array $object): array
    {
        return $this->processObjectUpdate('setProcessingTime', $object, 'processing-time update');
    }

    /**
     * Processes a single object update and keeps errors object-specific.
     *
     * @param string $method ApiWeb method name.
     * @param array<string,mixed> $object Object from Unicorn.
     * @param string $label Human-readable operation label.
     * @return array<string,mixed> Result item or errors.
     */
    private function processObjectUpdate(string $method, array $object, string $label): array
    {
        $marketplaceIdentifier = $this->resolveMarketplaceIdentifier($object);
        if ($marketplaceIdentifier === '') {
            return ['errors' => [ApiWebResponse::error(
                422,
                'Cannot perform ' . $label . ': no stable marketplace identifier found. Provide ShopId/MarketplaceId if the item was listed before, or provide SKU/ArtikelNummer/EAN so the adapter can resolve the marketplace offer id.'
            )]];
        }

        $failure = $this->failureMode();
        if ($failure === $method . ':quota') {
            return ['errors' => [ApiWebResponse::error(429, 'Marketplace request limit reached for ' . $label . '. Retry later.')]];
        }
        if ($failure === $method . ':api_down') {
            return ['errors' => [ApiWebResponse::error(503, 'Marketplace API is currently unreachable for ' . $label . '. Retry later.')]];
        }

        if ($this->mode() === 'demo') {
            return [
                'item' => [
                    'Success' => true,
                    'ShopId' => $marketplaceIdentifier,
                    'Message' => 'Demo ' . $label . ' accepted.',
                ],
            ];
        }

        $endpoint = $this->endpoint($method);
        if ($endpoint === '') {
            return ['errors' => [ApiWebResponse::error(503, 'No marketplace endpoint is configured for ' . $label . '.')]];
        }

        $response = $this->request('POST', $endpoint, $object);
        if (!$response['ok']) {
            return ['errors' => [$this->mapHttpError($response, $label)]];
        }

        return [
            'item' => [
                'Success' => true,
                'ShopId' => $marketplaceIdentifier,
                'Message' => 'Marketplace accepted ' . $label . '.',
            ],
        ];
    }

    /**
     * Resolves the best stable identifier for marketplace object updates.
     *
     * The preferred value is a marketplace-side id already stored in ShopId or
     * MarketplaceId. When a marketplace has a separate internal offer/unit id,
     * resolve it from SKU or EAN inside the real adapter before sending the
     * marketplace request.
     *
     * @param array<string,mixed> $object ApiWeb object from Unicorn.
     * @return string Stable identifier or an empty string when none is present.
     */
    private function resolveMarketplaceIdentifier(array $object): string
    {
        foreach (['MarketplaceId', 'MarketplaceOfferId', 'UnitId', 'ShopId', 'ArtikelNummer', 'Sku', 'SKU', 'SellerSku', 'SellerSKU', 'Ean', 'EAN', 'Gtin', 'GTIN'] as $field) {
            $value = trim((string)($object[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Sends an HTTP request to the configured marketplace.
     *
     * @param string $method HTTP method.
     * @param string $endpoint Relative endpoint or absolute URL.
     * @param array<string,mixed>|null $payload Optional JSON payload.
     * @return array<string,mixed> Normalized HTTP result.
     */
    private function request(string $method, string $endpoint, ?array $payload = null): array
    {
        $url = preg_match('/^https?:\/\//', $endpoint)
            ? $endpoint
            : rtrim((string)$this->config['marketplace']['base_url'], '/') . '/' . ltrim($endpoint, '/');

        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: unicorn2-apiweb-starter-marketplace/1.0',
        ];

        $clientId = (string)($this->config['marketplace']['client_id'] ?? '');
        $clientSecret = (string)($this->config['marketplace']['client_secret'] ?? '');
        if ($clientId !== '') {
            $headers[] = 'X-Marketplace-Client-Id: ' . $clientId;
        }
        if ($clientSecret !== '') {
            $headers[] = 'Authorization: Bearer ' . $clientSecret;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body === false ? '' : $body,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->extractStatusCode($http_response_header ?? []);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : [],
            'message' => is_string($raw) && $raw !== '' ? substr($raw, 0, 500) : 'HTTP status ' . $status,
        ];
    }

    /**
     * Maps marketplace orders to neutral ApiWeb order examples.
     *
     * @param array<string,mixed> $data Marketplace response data.
     * @return array<int,array<string,mixed>>
     */
    private function mapOrders(array $data): array
    {
        $orders = $data['orders'] ?? $data['data'] ?? [];
        if (!is_array($orders)) {
            return [];
        }

        $mapped = [];
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $mapped[] = [
                'ShopId' => (string)($order['id'] ?? $order['order_id'] ?? ''),
                'OrderNumber' => (string)($order['number'] ?? $order['order_number'] ?? ''),
                'Currency' => (string)($order['currency'] ?? 'EUR'),
                'TotalGross' => (float)($order['total'] ?? $order['total_gross'] ?? 0),
                'CreatedAtUtc' => (string)($order['created_at'] ?? gmdate('c')),
                'Buyer' => $order['buyer'] ?? [],
                'Positions' => $order['positions'] ?? $order['items'] ?? [],
            ];
        }

        return $mapped;
    }

    /**
     * Maps HTTP status codes to ApiWeb errors.
     *
     * @param array<string,mixed> $response Normalized HTTP response.
     * @param string $operation User-facing operation label.
     * @return array<string,mixed>
     */
    private function mapHttpError(array $response, string $operation): array
    {
        $status = (int)($response['status'] ?? 0);
        if ($status === 401 || $status === 403) {
            return ApiWebResponse::error($status, 'Marketplace rejected credentials or permissions during ' . $operation . '. Check credentials and required scopes.');
        }
        if ($status === 429) {
            return ApiWebResponse::error(429, 'Marketplace request limit reached during ' . $operation . '. Retry later and reduce request frequency.');
        }
        if ($status >= 500 || $status === 0) {
            return ApiWebResponse::error(503, 'Marketplace API is unreachable or returned a server error during ' . $operation . '. Retry later.');
        }

        return ApiWebResponse::error(999, 'Unexpected marketplace response during ' . $operation . ': ' . (string)$response['message']);
    }

    /**
     * Returns one deterministic demo order.
     *
     * @return array<string,mixed>
     */
    private function demoOrder(): array
    {
        return [
            'ShopId' => 'demo-order-1001',
            'OrderNumber' => 'DEMO-1001',
            'Currency' => 'EUR',
            'TotalGross' => 49.90,
            'CreatedAtUtc' => gmdate('c'),
            'Buyer' => [
                'Email' => 'buyer@example.invalid',
                'FirstName' => 'Demo',
                'LastName' => 'Buyer',
            ],
            'ShippingAddress' => [
                'Name' => 'Demo Buyer',
                'Street' => 'Sample Street 1',
                'Zip' => '12345',
                'City' => 'Berlin',
                'CountryIso2' => 'DE',
            ],
            'Positions' => [
                [
                    'ShopId' => 'demo-line-1',
                    'Sku' => 'DEMO-SKU-1',
                    'Name' => 'Demo Article',
                    'Quantity' => 1,
                    'PriceGross' => 49.90,
                ],
            ],
        ];
    }

    /**
     * Extracts an HTTP status code from response headers.
     *
     * @param array<int,string> $headers HTTP response headers.
     * @return int HTTP status code or 0.
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                return (int)$matches[1];
            }
        }

        return 0;
    }

    /**
     * Returns the configured connector mode.
     *
     * @return string Connector mode.
     */
    private function mode(): string
    {
        return (string)($this->config['connector']['mode'] ?? 'demo');
    }

    /**
     * Returns the active debug failure mode.
     *
     * @return string Failure mode.
     */
    private function failureMode(): string
    {
        if ($this->failureModeOverride !== null) {
            return $this->failureModeOverride;
        }

        return (string)($this->config['debug']['failure_mode'] ?? '');
    }

    /**
     * Returns a configured marketplace endpoint.
     *
     * @param string $method ApiWeb method name.
     * @return string Endpoint path or URL.
     */
    private function endpoint(string $method): string
    {
        return (string)($this->config['marketplace']['endpoints'][$method] ?? '');
    }
}
