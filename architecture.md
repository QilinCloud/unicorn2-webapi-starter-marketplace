# Starter Architecture

`api.php` receives signed Unicorn requests and dispatches to `classes/ApiWebEndpoint.php`.

`classes/MarketplaceClient.php` is the only file that should contain target marketplace calls. The ApiWeb security, envelope and response classes should remain stable.

