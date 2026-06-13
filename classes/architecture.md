# Classes Architecture

`ApiWebEndpoint` validates method shape and delegates marketplace work to `MarketplaceClient`.

`ApiWebSecurity` owns HMAC. `ApiWebResponse` owns the response envelope.

