# Unicorn 2 ApiWeb Starter Marketplace Connector

This repository is a neutral starter template for third-party marketplace connectors. It contains no OTTO, Amazon, eBay, Kaufland, Temu or Shopify logic. Replace only `classes/MarketplaceClient.php` with calls to the target marketplace API and keep the ApiWeb envelope, HMAC and response classes stable.

Public documentation:

- https://webservice.marcos-software.de/index.html
- https://webservice.marcos-software.de/build-connector.html
- https://webservice.marcos-software.de/adapter-cookbook.html
- https://webservice.marcos-software.de/mapping-cookbook.html
- https://webservice.marcos-software.de/error-matrix.html
- https://webservice.marcos-software.de/capabilities.html
- https://webservice.marcos-software.de/deployment.html
- https://webservice.marcos-software.de/troubleshooting.html
- https://webservice.marcos-software.de/endpoints.html
- https://webservice.marcos-software.de/conformance.html
- https://webservice.marcos-software.de/field-test-checklist.html
- https://webservice.marcos-software.de/ai-build-prompt.md
- https://webservice.marcos-software.de/openapi.yaml

## Local start

```powershell
$env:APIWEB_TEST_KEY='local-dev-api-key-2026'
$env:MARKETPLACE_MODE='demo'
php -S 127.0.0.1:18080 -t .
```

In Unicorn 2 configure:

```text
http://127.0.0.1:18080/api.php
```

## Implement a real marketplace

1. Keep `api.php`, `ApiWebSecurity`, `ApiWebEndpoint` and `ApiWebResponse` unchanged.
2. Put marketplace credentials into environment variables or protected hosting settings.
3. Replace the demo branches in `classes/MarketplaceClient.php`.
4. Map marketplace HTTP 401/403 to credential or permission problems.
5. Map HTTP 429 to ApiWeb error code `429`.
6. Map HTTP 5xx or network errors to ApiWeb error code `503`.
7. Map missing required marketplace identifiers to ApiWeb error code `422`.
8. Continue processing later objects when one object fails.

## HMAC rules that must not be changed

- Body hash is `Base64(SHA256(raw JSON body bytes))`, not hex.
- Signature is `Base64(HMAC-SHA256(canonical, shared ApiWeb key))`, not hex.
- Canonical lines are signature version, timestamp, nonce, ApiWeb method,
  transport method and body hash.
- Request transport method is `POST`.
- Response transport marker is `RESPONSE`.
- Response `X-Unicorn-Api-Method` must be the original method, for example
  `getOrders`, never `response`.
- Sign the exact raw JSON string that is sent over HTTP. Do not reformat JSON
  between hashing/signing and sending.

## Identifier rules for write methods

For `setStock`, `setPrice`, `setProcessingTime` and similar methods, prefer a
real marketplace id from `MarketplaceId`, `MarketplaceOfferId`, `UnitId` or
`ShopId`. If the target marketplace requires another offer/listing/unit id,
resolve it from `ArtikelNummer`, `Sku`, `SKU`, `SellerSku`, `EAN` or `GTIN`.
Return an object-level `404` or `422` only when the adapter cannot identify the
target object.

## Supported minimal methods

- `validateCredentials`
- `getCapabilities`
- `getOrders`
- `setOrderSend`
- `setStock`
- `setPrice`
- `setProcessingTime`

## Smoke tests

Start the local server and run:

```powershell
php tools/sign_request.php getCapabilities http://127.0.0.1:18080/api.php
php tools/sign_request.php validateCredentials http://127.0.0.1:18080/api.php
php tools/sign_request.php getOrders http://127.0.0.1:18080/api.php
```

Run the full conformance smoke suite:

```powershell
php tools/run_conformance.php http://127.0.0.1:18080/api.php
```

The conformance runner validates both request and response signatures. A
connector that returns valid JSON but signs the response with hex hashes,
`POST`, or `X-Unicorn-Api-Method: response` must fail before it is connected to
Unicorn.

Run the connector in `real` mode against the included local mock marketplace:

```powershell
# Terminal 1
php -S 127.0.0.1:18081 tools/mock_marketplace.php

# Terminal 2
$env:APIWEB_TEST_KEY='local-dev-api-key-2026'
$env:MARKETPLACE_MODE='real'
$env:MARKETPLACE_BASE_URL='http://127.0.0.1:18081'
$env:MARKETPLACE_ENDPOINT_VALIDATE='/auth/check'
$env:MARKETPLACE_ENDPOINT_ORDERS='/orders'
$env:MARKETPLACE_ENDPOINT_SHIPMENT='/shipments'
$env:MARKETPLACE_ENDPOINT_STOCK='/stock'
$env:MARKETPLACE_ENDPOINT_PRICE='/prices'
$env:MARKETPLACE_ENDPOINT_PROCESSING_TIME='/delivery-times'
$env:APIWEB_ALLOW_REQUEST_FAILURE_MODE='1'
php -S 127.0.0.1:18080 -t .

# Terminal 3
php tools/run_conformance.php http://127.0.0.1:18080/api.php
```

Negative tests:

```powershell
$env:APIWEB_FAILURE_MODE='invalid_credentials'
php tools/sign_request.php validateCredentials http://127.0.0.1:18080/api.php

$env:APIWEB_FAILURE_MODE='getOrders:quota'
php tools/sign_request.php getOrders http://127.0.0.1:18080/api.php

$env:APIWEB_FAILURE_MODE='getOrders:api_down'
php tools/sign_request.php getOrders http://127.0.0.1:18080/api.php
```

`tools/sign_request.php` adds the failure mode to the signed request body in demo mode. This keeps negative tests repeatable even when the PHP server was already started.

`tools/mock_marketplace.php` exposes `/auth/check`, `/orders`, `/shipments`, `/stock`, `/prices` and `/delivery-times`. Add `?failure=auth`, `?failure=forbidden`, `?failure=quota`, `?failure=validation`, `?failure=api_down` or `?failure=unknown` to simulate marketplace failures while writing an adapter.

`APIWEB_ALLOW_REQUEST_FAILURE_MODE=1` is only for local conformance runs against
the mock marketplace. Do not enable it in production hosting.

## Assumptions

- `ShopId` is the preferred marketplace-side identifier for update methods, but
  a real adapter may need to resolve a separate marketplace offer/listing/unit
  id from SKU or EAN.
- `getOrders` returns an ApiWeb collection; empty order lists are valid success responses.
- Unsupported features must be reported as `false` in `getCapabilities`.
- The target marketplace documentation defines the external authentication, pagination and field mapping rules. This starter only defines the Unicorn 2 side.

## Deployment checklist

- Determine the exact public URL to `api.php`; FTP hostings may expose a
  subfolder such as `/homepage/api.php`.
- Unsigned public calls to `api.php` should return a JSON `401` protocol error.
- A `404` means wrong public path; a `500` means PHP/server failure.
- `config.php`, `.env`, backups and logs must not be downloadable.
- Run a signed `validateCredentials` against the public URL before configuring
  Unicorn 2.
