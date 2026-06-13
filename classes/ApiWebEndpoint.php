<?php

/**
 * Dispatches Unicorn 2 ApiWeb methods to the marketplace adapter.
 */
final class ApiWebEndpoint
{
    /** @var array<string,mixed> */
    private array $config;

    private MarketplaceClient $marketplace;

    /**
     * Creates the ApiWeb endpoint.
     *
     * @param array<string,mixed> $config Connector configuration.
     * @param MarketplaceClient $marketplace Marketplace adapter.
     */
    public function __construct(array $config, MarketplaceClient $marketplace)
    {
        $this->config = $config;
        $this->marketplace = $marketplace;
    }

    /**
     * Handles one ApiWeb HTTP request.
     *
     * @param string $body Raw JSON request body.
     * @param array<string,string> $headers Request headers.
     * @return array{0:int,1:array<string,mixed>} HTTP status and ApiWeb answer.
     */
    public function handle(string $body, array $headers): array
    {
        $signatureError = null;
        if (!ApiWebSecurity::verifyRequest($this->config, $headers, $body, $signatureError)) {
            return [200, ApiWebResponse::protocolError(401, $signatureError ?? 'Invalid ApiWeb signature.', true)];
        }

        $request = json_decode($body, true);
        if (!is_array($request)) {
            return [200, ApiWebResponse::protocolError(400, 'Invalid JSON request body.', true)];
        }

        $method = (string)($request['Method'] ?? '');
        $headerMethod = $this->header($headers, 'X-Unicorn-Api-Method');
        if ($method === '' || $method !== $headerMethod) {
            return [200, ApiWebResponse::protocolError(400, 'Request Method must match X-Unicorn-Api-Method.', true)];
        }

        $objects = $request['Objects'] ?? [];
        if (!is_array($objects)) {
            return [200, ApiWebResponse::protocolError(400, 'Request Objects must be an array.', true)];
        }

        if (($this->config['connector']['mode'] ?? 'demo') === 'demo') {
            $failureMode = (string)($request['Debug']['FailureMode'] ?? '');
            if ($failureMode !== '') {
                $this->marketplace->setFailureMode($failureMode);
            }
        }

        switch ($method) {
            case 'validateCredentials':
                return [200, ApiWebResponse::answer([
                    ApiWebResponse::result($this->marketplace->validateCredentials()),
                ])];

            case 'getCapabilities':
                return [200, ApiWebResponse::answer([
                    ApiWebResponse::result($this->capabilities()),
                ])];

            case 'getOrders':
                $orderResult = $this->marketplace->getOrders($this->firstObject($objects));
                return [200, ApiWebResponse::answer([
                    ApiWebResponse::result(null, $orderResult['collection'] ?? [], $orderResult['errors'] ?? []),
                ])];

            case 'setOrderSend':
            case 'setStock':
            case 'setPrice':
            case 'setProcessingTime':
                return [200, ApiWebResponse::answer($this->processObjects($method, $objects))];

            default:
                return [200, ApiWebResponse::protocolError(501, 'ApiWeb method ' . $method . ' is not supported by this connector.', false)];
        }
    }

    /**
     * Processes object update methods and continues after object errors.
     *
     * @param string $method ApiWeb method.
     * @param array<int,mixed> $objects Request objects.
     * @return array<int,array<string,mixed>>
     */
    private function processObjects(string $method, array $objects): array
    {
        $results = [];
        foreach ($objects as $object) {
            if (!is_array($object)) {
                $results[] = ApiWebResponse::result(null, [], [
                    ApiWebResponse::error(422, 'Request object is not a JSON object.'),
                ]);
                continue;
            }

            $result = $this->marketplace->{$method}($object);
            $results[] = ApiWebResponse::result($result['item'] ?? null, [], $result['errors'] ?? []);
        }

        return $results;
    }

    /**
     * Returns connector capabilities consumed by Unicorn 2.
     *
     * @return array<string,mixed>
     */
    private function capabilities(): array
    {
        return [
            'ConnectorName' => (string)($this->config['connector']['name'] ?? 'Generic Marketplace ApiWeb Connector'),
            'ConnectorVersion' => (string)($this->config['connector']['version'] ?? '1.0.0'),
            'SupportedLanguages' => ['de', 'en'],
            'SupportedCurrencies' => ['EUR'],
            'SupportedPaymentMethods' => ['Unknown'],
            'SupportedMethods' => [
                'validateCredentials',
                'getCapabilities',
                'getOrders',
                'setOrderSend',
                'setStock',
                'setPrice',
                'setProcessingTime',
            ],
            'Features' => [
                'Orders' => true,
                'ShipmentUpload' => true,
                'StockUpload' => true,
                'PriceUpload' => true,
                'ProcessingTimeUpload' => true,
                'DetectStatus' => false,
                'PortalCategories' => false,
                'InvoiceFileUpload' => false,
                'InvoiceDataUpload' => false,
                'RefundFileUpload' => false,
                'RefundDataUpload' => false,
                'Purge' => false,
            ],
        ];
    }

    /**
     * Returns the first request object or an empty filter.
     *
     * @param array<int,mixed> $objects Request objects.
     * @return array<string,mixed>
     */
    private function firstObject(array $objects): array
    {
        return isset($objects[0]) && is_array($objects[0]) ? $objects[0] : [];
    }

    /**
     * Reads one request header case-insensitively.
     *
     * @param array<string,string> $headers Request headers.
     * @param string $name Header name.
     * @return string Header value.
     */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) === 0) {
                return (string)$value;
            }
        }

        $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$serverName]) ? (string)$_SERVER[$serverName] : '';
    }
}
