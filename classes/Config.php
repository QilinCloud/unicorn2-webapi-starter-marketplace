<?php

/**
 * Loads connector configuration from config.php and applies safe defaults.
 */
final class Config
{
    /**
     * Loads the connector configuration file.
     *
     * @param string $path Absolute path to config.php.
     * @return array<string,mixed>
     */
    public static function load(string $path): array
    {
        $loaded = file_exists($path) ? require $path : [];
        if (!is_array($loaded)) {
            $loaded = [];
        }

        return array_replace_recursive(self::defaults(), $loaded);
    }

    /**
     * Returns default settings for local development.
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        return [
            'apiweb' => [
                'secret' => 'local-dev-api-key-2026',
                'signature_version' => '2026-06-13.hmac-sha256',
                'timestamp_tolerance_seconds' => 300,
            ],
            'connector' => [
                'name' => 'Generic Marketplace ApiWeb Connector',
                'version' => '1.0.0',
                'mode' => 'demo',
                'log_level' => 'info',
            ],
            'marketplace' => [
                'base_url' => '',
                'client_id' => '',
                'client_secret' => '',
                'endpoints' => [],
            ],
            'debug' => [
                'failure_mode' => '',
            ],
        ];
    }
}

