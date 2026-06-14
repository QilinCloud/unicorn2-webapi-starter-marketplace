# Starter Components

- `api.php`: HTTP entry point.
- `config.php`: local and hosting configuration.
- `classes/ApiWebSecurity.php`: HMAC request and response signing.
- `classes/ApiWebEndpoint.php`: ApiWeb method dispatch.
- `classes/ApiWebResponse.php`: response envelope helpers.
- `classes/MarketplaceClient.php`: replaceable marketplace adapter.
- `tools/sign_request.php`: signed local request helper.
- `tools/run_conformance.php`: public ApiWeb contract test runner.
- `tools/mock_marketplace.php`: local mock marketplace for adapter tests.
