# Changelog

All notable changes to `angeo/module-ucp` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-07-04

Extensibility release: multi-transport service bindings and automated
schema-drift detection. Spec version 2026-04-08 remains the latest UCP
release; the official roadmap signals Lodging and Food verticals next, so
the services registry is now open for extension without core changes.

### Added

- **MCP transport binding.** New admin field *Transport → MCP Endpoint URL*
  (`angeo_ucp/transport/mcp_endpoint`). When set, the profile advertises an
  additional `mcp` binding for `dev.ucp.shopping` alongside REST — per
  `schemas/service.json`, each transport binding is a separate entry in the
  same service array, and `mcp` (like `rest`) REQUIRES an `endpoint` in
  business profiles. The MCP endpoint is opt-in only and never derived from
  the store base URL: an MCP server (e.g. `angeo/module-mcp-server`'s
  Streamable HTTP endpoint) is separate infrastructure.
- **`Api\ServiceBindingProviderInterface` (@api) + DI provider pool.**
  `ProfileGenerator` no longer hardcodes the services registry; it merges
  registry fragments from a di.xml-configured pool
  (`serviceBindingProviders`). Built-in providers:
  `Model\Service\RestShoppingBindingProvider` (extracted, behavior
  unchanged) and `Model\Service\McpShoppingBindingProvider`. Third-party
  modules — a future catalog implementation, additional transports, or the
  upcoming Lodging/Food verticals — contribute bindings via their own
  di.xml without touching this module. Malformed fragments
  (non-reverse-domain keys, non-list values) are dropped defensively.
- **CI schema validation** (`.github/workflows/schema-validation.yml` +
  `dev/schema-validation/`). Fixture profiles are generated through the
  real module code (framework interfaces stubbed, no Magento install
  needed) and validated against the OFFICIAL JSON Schemas at the pinned
  spec tag on every push/PR and weekly. When the next dated spec release
  lands, bumping `UCP_SPEC_TAG` immediately reveals any schema drift.
  Fixtures cover: full profile (REST + MCP), minimal, MCP-only, and the
  orphan-extension pruning case.

### Changed

- `Config::getRestEndpoint()` / `getMcpEndpoint()` trim surrounding
  whitespace from configured values, preventing a whitespace-only value
  from being advertised as an endpoint.
- `dev.ucp.shopping` service name centralised as
  `Config::SHOPPING_SERVICE_NAME`.

### Upgrade notes

- `ProfileGenerator::__construct()` gained a second argument
  (`array $serviceBindingProviders = []`). Wired automatically via di.xml;
  only code instantiating the class directly (e.g. tests) needs updating.
- Behavior is unchanged for existing installs: with no MCP endpoint
  configured, the emitted profile is byte-identical.

Schema-compliance release. The generated profile is now validated end-to-end
against the **official JSON Schemas** from the spec repository
(`Universal-Commerce-Protocol/ucp`, tag `v2026-04-08`:
`source/discovery/profile_schema.json`, `source/schemas/ucp.json`,
`service.json`, `capability.json`, `payment_handler.json`). Spec version
2026-04-08 remains the latest UCP release as of July 2026.

### Fixed

- **`ucp.services` and `ucp.payment_handlers` were omitted when empty —
  schema violation.** `ucp.json#/$defs/business_schema` marks BOTH keys as
  REQUIRED in a business profile, even when empty. Previously
  `payment_handlers` was dropped when not configured and `services` was
  emitted as an empty value only implicitly. Both keys (plus `capabilities`)
  are now always present.
- **Empty registries JSON-encoded as `[]` instead of `{}` — type violation.**
  `services`, `capabilities`, and `payment_handlers` are JSON *objects* keyed
  by reverse-domain name. Empty PHP arrays encode as JSON arrays, so a store
  with no capabilities served a profile that failed schema validation
  (`[] is not of type 'object'`). Empty registries are now emitted as
  `stdClass` and serialize as `{}`.
- **Admin-supplied `payment_handlers` were passed through unvalidated.**
  Per `payment_handler.json#/$defs/base`, every handler entry REQUIRES a
  string `id` and a `YYYY-MM-DD` `version`; the registry must be keyed by
  reverse-domain names with array values. `Config::getPaymentHandlers()` now
  enforces all of this, skipping invalid keys/entries with logged warnings so
  the served profile always validates. A single entry object is tolerated and
  wrapped into the required array form.

### Added

- **CORS preflight support.** The `/.well-known/ucp` action now also
  implements `HttpOptionsActionInterface` and answers `OPTIONS` with
  `204 No Content` plus `Allow`, `Access-Control-Allow-*`, and
  `Access-Control-Max-Age` headers. Previously Magento's HTTP-method
  validation rejected OPTIONS even though the response advertised
  `Access-Control-Allow-Methods: GET, OPTIONS`. The preflight responds
  identically whether or not the profile is enabled, so it does not leak
  the advertised state.
- **Controller unit tests** covering the OPTIONS preflight, the disabled
  404 (`no-store`), the 200 response headers required by the spec's hosting
  rules (`Content-Type: application/json`, CORS, `Cache-Control: public`
  with `max-age >= 60`), and the 500 error path.
- **Admin warning on Identity Linking.** The `identity_linking_enabled`
  field now documents that declaring this capability requires publishing
  OAuth 2.0 authorization server metadata at
  `/.well-known/oauth-authorization-server` (RFC 8414), which is outside
  this module's scope.

### Changed

- **`angeo:ucp:validate` rewritten against the official schemas.** New
  checks: presence of the required `services`/`payment_handlers` keys;
  reverse-domain pattern for all registry keys; `endpoint` REQUIRED for
  rest/mcp/a2a service bindings in business profiles; payment handler
  `id`/`version` requirements; `kid`/`kty` REQUIRED on signing keys.
  Relaxed to match the business schema: capability `spec`/`schema` are now
  warnings (optional at business level; only `version` is REQUIRED), and an
  empty `services` registry is a warning rather than an error (schema-valid,
  but agents have nothing to call).
- Reverse-domain validation uses the exact pattern from the spec's
  `reverse_domain_name.json` type (`Config::REVERSE_DOMAIN_PATTERN`).

### Verified (no change needed)

- Protocol version `2026-04-08` is still the latest UCP release.
- `signing_keys` correctly published as a top-level sibling of `ucp`
  (`profile_schema.json#/$defs/base`).
- Catalog correctly split into `catalog.search` + `catalog.lookup`;
  extensions correctly pruned when orphaned; `identity_linking` correctly
  under `dev.ucp.common` with `config.scopes` as an object map; ES256 /
  P-256 JWK signing keys match the Signatures spec (RFC 9421 foundation).

## [1.2.0] - 2026-06-13

Fixes the endpoint wiring so `/.well-known/ucp` is actually served by the
controller, and removes a conflicting static-file code path.

### Fixed

- **Router registered in the wrong area — endpoint always 404'd.** The
  `RouterList` entry for the custom router was placed in the global
  `etc/di.xml`. The frontend "standard" `RouterList` is frontend-scoped, so a
  global registration is not reliably applied to it: the router's `match()`
  never ran and every request fell through to the CMS router (HTTP 404,
  `text/html`, with Magento page-cache tags). The registration is now in
  `etc/frontend/di.xml`, which actually wires the router into frontend routing.
  This was the underlying cause behind the earlier 404 reports.
- **404 on LiteSpeed / Hostinger (and other non-nginx servers).** The router
  matched only `getPathInfo()` with a strict comparison. On LiteSpeed the
  `PATH_INFO` for a dot-segment path is frequently empty, so the comparison
  failed and the request fell through to Magento's CMS router (HTTP 404,
  `text/html`). The router now checks `getPathInfo()`, `getOriginalPathInfo()`
  and `getRequestUri()`, strips the query string, and normalises slashes, so it
  matches `/.well-known/ucp` regardless of how the web server populates the path.
- **Missing DI preference for `ProfileGeneratorInterface`.** The controller and
  the `angeo:ucp:validate` command depend on the interface, but no
  `<preference>` bound it to `Model\ProfileGenerator`, so `setup:upgrade` failed
  with "Cannot instantiate interface Angeo\Ucp\Api\ProfileGeneratorInterface".
  This was latent until the router was wired up (nothing previously
  instantiated the controller). The preference is now declared in `etc/di.xml`.
- **Custom router was never registered.** `Controller\Router` existed but no
  `RouterList` entry referenced it (and there was no `etc/frontend/routes.xml`),
  so the controller was dead code. As a result `/.well-known/ucp` either
  returned 404, or — when a static file was present — was served by the web
  server as `application/octet-stream` with no CORS headers, failing UCP
  validation. The router is now registered in `etc/di.xml` via `RouterList`
  (sortOrder 22) and a `etc/frontend/routes.xml` declares the route, so the
  controller serves the endpoint with the correct headers.
- **Missing CORS headers.** The controller now sends
  `Access-Control-Allow-Origin: *` and `Access-Control-Allow-Methods: GET, OPTIONS`
  on every response, as required for cross-origin fetches by AI agents.

### Removed

- **Static-file approach deleted.** `Service\WellKnownWriter`,
  `Service\UcpProfileBuilder`, and the `angeo:ucp:generate` command
  (`Console\Command\GenerateWellKnown`) were removed. Writing a static
  `pub/.well-known/ucp` conflicted with the controller — the web server served
  the static file first, with the wrong Content-Type — and the builder was a
  stub duplicate of `Model\ProfileGenerator`. The controller is now the single
  source of truth for the endpoint.
- Removed a duplicate `di.xml` from the module root (Magento only reads
  `etc/di.xml`).

### Housekeeping

- Removed macOS `__MACOSX` resource-fork artifacts from the package.

## [1.1.0] - 2026-06-12

Full review against the live UCP 2026-04-08 specification at
[ucp.dev](https://ucp.dev/2026-04-08/specification/overview/). One
spec-compliance defect was found and fixed; the profile generator now covers
every business-profile feature defined by the spec.

### Fixed — spec compliance

- **[SPEC] Catalog capability naming corrected.** The spec defines catalog as
  TWO granular capabilities — `dev.ucp.shopping.catalog.search` and
  `dev.ucp.shopping.catalog.lookup` — with distinct spec pages
  (`/specification/catalog/search`, `/specification/catalog/lookup`) and
  schemas (`catalog_search.json`, `catalog_lookup.json`). The previous
  monolithic `dev.ucp.shopping.catalog` capability does not exist in the spec
  and would not match any platform's declared capability during the
  intersection algorithm, silently disabling catalog for all AI agents.
  **Backwards compatible:** the legacy `catalog_enabled` config value is still
  honoured and enables both granular capabilities.

### Added — full business-profile coverage

- **Extensions with `extends` declarations** per spec:
  - `dev.ucp.shopping.fulfillment` (extends `dev.ucp.shopping.checkout`)
  - `dev.ucp.shopping.discount` (extends checkout and/or cart; emits a string
    for a single parent and an array for multiple parents, per spec)
- **Orphaned-extension pruning.** Extensions are never advertised when no
  parent capability is enabled — the spec prunes orphans during negotiation,
  so advertising one is always dead weight. The generator enforces this and
  the validator reports it as an error.
- **Identity-linking OAuth scopes.** New admin field publishes
  `config.scopes` (e.g. `dev.ucp.shopping.order:read`) under
  `dev.ucp.common.identity_linking`; scope values encode as empty JSON
  objects `{}` exactly as the spec example shows.
- **`supported_versions`** (spec SHOULD for businesses supporting older
  protocol versions): admin JSON field, validated — keys must be YYYY-MM-DD,
  URIs must be HTTPS; invalid entries are skipped with a logged warning.
- **`payment_handlers`** declaration: admin JSON field, validated to be a
  JSON object keyed by reverse-domain handler name; arrays and malformed
  JSON are rejected with a logged warning. Documentation warns that profile
  contents are public and must not contain secret keys.
- **Validator upgraded to a spec-conformance checker:**
  - capability required fields (`version`, `spec`, `schema` — spec: REQUIRED)
  - service-binding required fields (`version`, `spec`, `transport`)
  - Spec URL Binding: `dev.ucp.*` capabilities must point at
    `https://ucp.dev/` origins (spec: MUST; platforms reject mismatches)
  - orphaned-extension detection
  - trailing-slash warning on endpoints (spec: SHOULD NOT)
  - `supported_versions` format checks
  - private-key-material detection in published `signing_keys`

### Changed

- README rewritten with an explicit protocol-coverage matrix documenting
  which UCP layers this module implements (discovery) and which require
  separate endpoint implementations (negotiation, transactional REST
  endpoints, RFC 9421 response signatures, payment handler execution).
- Admin capability section renamed to "Declared Root Capabilities" with a
  new "Declared Extensions" section; comments clarify that enabling a
  capability advertises it but does not implement the endpoint.
- `module.xml` setup_version and composer version bumped to 1.1.0.

### Notes — protocol coverage

This module deliberately implements the **discovery layer** of UCP
(business profile + signing keys + hosting rules). The transactional flow —
capability negotiation, catalog/cart/checkout/order endpoints, RFC 9421
message signatures, payment execution — is the responsibility of endpoint
modules that this profile advertises. Enable capability toggles only when
the matching endpoint implementation is installed.

## [1.0.0] - 2026-06-11

First production-ready release. All items below are security fixes or
reliability improvements over the `0.1.x` beta line.

### Security

- **[SECURITY] HTTP endpoint validation in `Config::getRestEndpoint()`.**
  Configured and auto-derived endpoint URLs are now checked for the
  `https://` scheme. A `warning` is emitted to `system.log` if a
  non-HTTPS URL is detected, alerting the operator before AI agents
  reject the profile. The UCP spec requires HTTPS for `/.well-known/ucp`.

- **[SECURITY] Over-length coordinate rejection in `JwkFormatter`.**
  Coordinates longer than 32 bytes (the P-256 fixed width) are now
  rejected with a `RuntimeException` instead of being silently included.
  Previously only short coordinates were left-padded; an over-long value
  from a malformed key would have produced an invalid JWK without error.

- **[SECURITY] Security response headers added to all controller responses.**
  `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, and
  `Referrer-Policy: no-referrer` are now set on every response from the
  UCP controller (200, 404, and 500). This aligns with standard Magento
  hardening practices.

- **[SECURITY] JWK entry validation before returning public keys.**
  `Config::getPublicSigningKeys()` now validates that each entry in the
  stored JWK array contains the required fields (`kid`, `kty`, `crv`,
  `x`, `y`) before including it in the profile. Malformed entries are
  skipped and a `warning` is logged.

- **[SECURITY] Empty `kid` rejection in `JwkFormatter`.**
  `publicKeyToJwk()` now throws if `$kid` is an empty string, preventing
  a JWK with a blank key identifier from being emitted.

### Fixed

- **`GenerateKeysCommand` scope mismatch.**
  The existing-key check now reads at `SCOPE_TYPE_DEFAULT` (matching the
  scope used by `ConfigWriter::saveConfig()`). Previously reading at
  store scope could return an empty string even when a key was already
  stored at default scope, allowing the command to silently overwrite it.

- **`GenerateKeysCommand` uses `invalidate()` instead of `cleanType()`.**
  `TypeListInterface::invalidate('config')` marks the config cache as
  dirty without flushing unrelated cache types. `cleanType()` is a
  heavier operation that is unnecessary here.

- **`ValidateProfileCommand` HTTPS check.**
  The validator now explicitly checks that every declared service-binding
  endpoint URL uses `https://`. Previously a profile with an `http://`
  endpoint would pass validation even though the UCP spec forbids it.

- **`ValidateProfileCommand` severity adjustment.**
  The "no capabilities and no signing keys" condition is now a `warning`
  rather than a hard `FAILURE`. A discovery-only deployment that has
  no capabilities yet is structurally valid; operators are warned but
  the command exits `0` so CI pipelines are not broken during initial
  setup.

- **`KeyGenerator` uses `extension_loaded()` instead of `function_exists()`**
  to check for OpenSSL. `function_exists('openssl_pkey_new')` is true
  even when the extension is partially initialised on some platforms;
  `extension_loaded('openssl')` is the canonical check.

- **`KeyGenerator` kid entropy increased from 2 to 4 bytes.**
  4 bytes of CSPRNG output (8 hex characters, ~4 billion distinct values)
  makes accidental kid collisions negligible even in automated
  high-rotation environments.

- **`module.xml` now declares `setup_version="1.0.0"`.**

### Removed

- `extra.branch-alias` removed from `composer.json`; no longer needed
  for a stable tagged release.

---

## [0.1.1-beta] - 2026-05-23

### Fixed

- Removed the static `"version"` field from `composer.json`. Packagist
  now reads the version from the git tag, which is the canonical
  Composer practice and eliminates the risk of the manifest version
  drifting from the released tag.

### Changed

- Installation now requires the explicit `@beta` stability qualifier:
  `composer require angeo/module-ucp:^0.1@beta`.
- README updated: roadmap row, install instructions, and a note that
  `0.1.0` is yanked.
- `extra.branch-alias` mapping `dev-main` to `0.1.x-dev` added.

---

## [0.1.0-beta] - 2026-05-23

### Fixed

- **Critical:** `composer.json` was invalid JSON (trailing comma after
  `support.source`), preventing `composer require` from succeeding.
- `magento/module-store` is now explicitly required in `composer.json`
  and listed in `module.xml` `<sequence>`.
- Removed dead `etc/frontend/routes.xml`.
- Removed unused imports in `Controller/WellKnown/Ucp.php`.

### Changed

- `Angeo\Ucp\Model\Config` now takes a `Psr\Log\LoggerInterface` constructor argument.
- `Controller\WellKnown\Ucp` now catches non-`JsonException` throwables.

### Added

- `SECURITY.md`, `ext-openssl` and `ext-json` composer requirements.
- Two new `ConfigTest` cases covering logging behaviour.

---

## [0.1.0] - 2026-05-21 *(yanked — composer.json was invalid JSON)*

### Added

- Initial public release.
