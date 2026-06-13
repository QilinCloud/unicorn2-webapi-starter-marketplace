<?php

/**
 * Implements Unicorn 2 ApiWeb HMAC-SHA256 request and response signing.
 */
final class ApiWebSecurity
{
    /**
     * Validates the incoming ApiWeb request headers and body.
     *
     * @param array<string,mixed> $config Connector configuration.
     * @param array<string,string> $headers Request headers.
     * @param string $body Raw JSON request body.
     * @param string|null $error Receives a user-facing error message.
     * @return bool True when the signature is valid.
     */
    public static function verifyRequest(array $config, array $headers, string $body, ?string &$error): bool
    {
        $normalized = self::normalizeHeaders($headers);
        $method = $normalized['x-unicorn-api-method'] ?? '';
        $timestamp = $normalized['x-unicorn-timestamp'] ?? '';
        $nonce = $normalized['x-unicorn-nonce'] ?? '';
        $bodyHash = strtolower($normalized['x-unicorn-body-sha256'] ?? '');
        $signature = strtolower($normalized['x-unicorn-signature'] ?? '');
        $version = $normalized['x-unicorn-signature-version'] ?? '';

        if ($method === '' || $timestamp === '' || $nonce === '' || $bodyHash === '' || $signature === '') {
            $error = 'Missing ApiWeb signature headers. Check X-Unicorn-* headers.';
            return false;
        }

        if ($version !== ($config['apiweb']['signature_version'] ?? '2026-06-13.hmac-sha256')) {
            $error = 'Unsupported ApiWeb signature version.';
            return false;
        }

        $expectedBodyHash = hash('sha256', $body);
        if (!hash_equals($expectedBodyHash, $bodyHash)) {
            $error = 'ApiWeb body hash mismatch. The request body changed after signing.';
            return false;
        }

        $now = time();
        $requestTime = (int)$timestamp;
        $tolerance = (int)($config['apiweb']['timestamp_tolerance_seconds'] ?? 300);
        if ($requestTime <= 0 || abs($now - $requestTime) > $tolerance) {
            $error = 'ApiWeb timestamp is outside the allowed replay window.';
            return false;
        }

        $expected = self::signature($config, $method, $timestamp, $nonce, $bodyHash);
        if (!hash_equals($expected, $signature)) {
            $error = 'ApiWeb signature mismatch. Check shared API key and canonical string.';
            return false;
        }

        return true;
    }

    /**
     * Emits response signature headers for the JSON response body.
     *
     * @param array<string,mixed> $config Connector configuration.
     * @param string $body Response JSON body.
     * @return void
     */
    public static function emitResponseHeaders(array $config, string $body): void
    {
        $method = 'response';
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(12));
        $bodyHash = hash('sha256', $body);
        $signature = self::signature($config, $method, $timestamp, $nonce, $bodyHash);

        header('X-Unicorn-Signature-Version: ' . ($config['apiweb']['signature_version'] ?? '2026-06-13.hmac-sha256'));
        header('X-Unicorn-Api-Method: ' . $method);
        header('X-Unicorn-Timestamp: ' . $timestamp);
        header('X-Unicorn-Nonce: ' . $nonce);
        header('X-Unicorn-Body-Sha256: ' . $bodyHash);
        header('X-Unicorn-Signature: ' . $signature);
    }

    /**
     * Calculates the ApiWeb HMAC signature.
     *
     * @param array<string,mixed> $config Connector configuration.
     * @param string $method ApiWeb method name.
     * @param string $timestamp Unix timestamp as string.
     * @param string $nonce Request nonce.
     * @param string $bodyHash SHA256 hash of the body.
     * @return string Lowercase hexadecimal HMAC.
     */
    public static function signature(array $config, string $method, string $timestamp, string $nonce, string $bodyHash): string
    {
        $secret = (string)($config['apiweb']['secret'] ?? '');
        return hash_hmac('sha256', self::canonicalString($method, $timestamp, $nonce, $bodyHash), $secret);
    }

    /**
     * Builds the canonical string used by ApiWeb signatures.
     *
     * @param string $method ApiWeb method name.
     * @param string $timestamp Unix timestamp as string.
     * @param string $nonce Request nonce.
     * @param string $bodyHash SHA256 hash of the body.
     * @return string Canonical string with newline separators.
     */
    public static function canonicalString(string $method, string $timestamp, string $nonce, string $bodyHash): string
    {
        return $method . "\n" . $timestamp . "\n" . $nonce . "\n" . strtolower($bodyHash);
    }

    /**
     * Normalizes headers to lowercase names.
     *
     * @param array<string,string> $headers Request headers.
     * @return array<string,string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = (string)$value;
        }

        foreach ($_SERVER as $name => $value) {
            if (strpos($name, 'HTTP_X_UNICORN_') === 0) {
                $header = strtolower(str_replace('_', '-', substr($name, 5)));
                $normalized[$header] = (string)$value;
            }
        }

        return $normalized;
    }
}

