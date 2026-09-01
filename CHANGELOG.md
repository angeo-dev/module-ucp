# Changelog

All notable changes to `angeo/module-ucp` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.0] - 2026-08-28

Adds the piece 2.0.0 published keys for but never used: verification of
inbound RFC 9421 signatures. Also fixes a discovery-cache defect found while
reviewing an external critique of the module.

### Added

- **`Api\SignatureVerifierInterface`** — verification of inbound RFC 9421
  signatures, implementing the spec's identity-resolution algorithm. It lives
  here rather than in an endpoint module because every UCP endpoint needs the
  same logic and key resolution runs through the profile machinery this module
  already owns. `angeo/module-ucp-catalog` 2.1.0 consumes it.

  Why this and not response signing first: the spec's MUST runs in the
  *inbound* direction — a business MUST reject a request whose counterparty
  profile cannot be fetched or fails validation. Until 2.1.0 the endpoints
  advertised by this profile answered anyone who sent a POST. Signing our own
  responses is still to come, and is the weaker obligation of the two.

  What is implemented:
  - Signature base reconstruction (`Model\Signature\SignatureBase`) including
    the derived components `@method`, `@authority`, `@path`, `@query`,
    `@scheme`, `@target-uri`, and RFC 9421 §2.1.2 dictionary-member selection
    so `signature-agent;key="sig1"` can be covered.
  - `ecdsa-p256-sha256`, `ecdsa-p384-sha384` and `ed25519`. ECDSA signatures
    arrive as raw `r||s` and are converted to DER for OpenSSL; the key's
    `kty`/`crv` must match the nominated `alg`, so a signer cannot downgrade
    to a weaker algorithm than the profile advertises.
  - **Covered-component enforcement.** A signature that does not cover the
    request target, the body digest when a body is present, or a present
    `ucp-agent` / `signature-agent` / `idempotency-key` header is skipped —
    an uncovered component is an unsigned component.
  - **Content-Digest is checked against the body**, not merely required to be
    covered. Covering a digest header that nobody validates authenticates
    nothing.
  - Multiple signatures per request: each is attempted independently and the
    request is authenticated when at least one verifies, per RFC 9421 §4.3.
  - For `tag="web-bot-auth"`, `keyid` MUST equal the RFC 7638 thumbprint of
    the matched JWK — the binding 2.0.0's thumbprint kids exist for.
  - Keys marked `use: "enc"`, or with `key_ops` lacking `"verify"`, are
    skipped during resolution (RFC 7517 §4.2/§4.3).
  - `created` in the future and `expires` in the past are rejected, with a
    300-second skew allowance.

- **`Model\Signature\ProfileFetcher`** — implements the spec's Fetching
  rules, which are normative precisely because this class dereferences a URL
  supplied by the request and is an SSRF primitive otherwise: HTTPS only, no
  redirect following, **resolved-address** checks against RFC 6890 special-use
  ranges (including the cloud metadata address `169.254.169.254`) so DNS
  rebinding does not slip through, a 128 KiB body bound, connect and response
  timeouts, and a 60-second cache TTL floor. On an unknown `kid` the profile is
  force-refreshed once — a counterparty that just rotated should not be locked
  out for a whole TTL — rate-limited to once per origin per TTL floor.

- **Admin setting: `Request Security → Inbound Signature Verification`**, with
  three modes.
  - `disabled` (default) — 2.0.x behaviour, everything served unverified.
  - `optional` — the migration mode: a counterparty that signs is
    authenticated, one that does not is still served. An **invalid** signature
    is rejected here too; what `optional` relaxes is only the *absence* of one.
  - `required` — unsigned requests get 401.

  Default is `disabled` on purpose. No agent signs requests to a store that has
  never advertised signature support, so defaulting to `required` would 401
  every existing integration on upgrade. An unrecognised stored value also
  falls back to `disabled` rather than `required` — a configuration typo should
  not take a store's UCP endpoints offline.

### Fixed

- **Discovery-cache fragmentation.** The 2.0.0 router normalised trailing
  slashes before matching, so `/.well-known/ucp` and `/.well-known/ucp/`
  both served the profile. Two URLs returning identical bytes are two CDN
  cache entries, halving the hit rate on the single most-fetched document the
  module serves. 2.1.0 serves the canonical path only and issues a **301** for
  the trailing-slash form, preserving the query string.

  For the record, the related claim that the module also serves
  `/.well-known/ucp.json` is not accurate: no such route exists in the module,
  and no such path exists anywhere in the specification at tag `v2026-08-25`.
  Only the trailing-slash variant was real.

### Changed

- `ext-curl` is now a hard requirement rather than assumed. Verification
  cannot resolve a counterparty's keys without it, and failing at install time
  is better than failing per-request.

### Testing

- `dev/signature-verification/verify_test.php` runs the verifier against a real
  Ed25519 keypair with no Magento or network dependency. Thirteen cases,
  including: tampered body, swapped `@path`, a query string appended after
  signing, a signature omitting `content-digest` from its covered set, a
  present `idempotency-key` left uncovered, `alg` substitution, a key marked
  `use: "enc"`, an unknown `kid`, and `Signature-Input` sent without
  `Signature`.

## [2.0.0] - 2026-08-28

Adopts UCP spec release **2026-08-25**, published on 25 August 2026 — the
first new dated release since 2026-04-08. This is a major version because the
profile's key field changed name and the advertised protocol version changed;
both are visible to every platform that fetches `/.well-known/ucp`.

Everything below was verified against `Universal-Commerce-Protocol/ucp` at
tag `v2026-08-25`, and the profiles this module generates are validated in CI
against that tag's official JSON Schemas.

### A note on what 1.4.0 actually was

1.4.0 was **not** broken. Its profile was, and still is, a valid
`2026-04-08` business profile — `signing_keys` was the correct field for that
release, and the spec explicitly allows a business to keep serving an older
version. Staying on 1.4.0 is a legitimate choice.

What 1.4.0 cannot do is advertise `2026-08-25`. The two releases disagree
about where keys live and where capability documentation lives, so adopting
the new version means adopting all of it at once. That is what this release
does.

### Changed — BREAKING

- **Protocol version is now `2026-08-25`** (`Config::PROTOCOL_VERSION`). The
  previous value is retained as `Config::PREVIOUS_PROTOCOL_VERSION` for
  migration tooling.

- **Signing keys moved from `signing_keys` to the top-level `keys[]` array.**
  Per `schemas/profile.json#/$defs/base` at the new tag, `keys[]` is the
  canonical field and "this is where every UCP verifier reads them". It is an
  RFC 7517 JWK Set, which makes the profile document simultaneously a valid
  JWK Set that a signer can reuse as its Web Bot Auth key source. A profile
  advertising `2026-08-25` while still using `signing_keys` publishes keys
  nothing will read — `angeo:ucp:validate` now reports that as an error.
  No configuration change is needed: the stored JWKs are re-published under
  the new field automatically.

- **All capability `spec` URLs moved.** The documentation tree was
  reorganised: catalog, cart, checkout and order now live under
  `/specification/shopping/`, and fulfillment, discount and buyer_consent
  under `/specification/shopping/extensions/`. Every URL the profile emits is
  now centralised in the new `Model\Spec` class rather than assembled from
  string fragments in three different files.

- **`Config::getPublicSigningKeys()` is deprecated** in favour of
  `getPublicKeys()`. The old method still works and forwards to the new one.

- **Reverse-domain pattern widened** to the pattern in
  `schemas/common/types/reverse_domain_name.json` at the new tag. The 1.x
  pattern rejected names the spec lists as valid — interior hyphens
  (`com.example-shop.checkout`), leading digits after the first segment
  (`com.2example.cart`), and punycode TLDs (`xn--p1ai.example.checkout`).
  A payment handler or third-party service binding using any of those was
  silently dropped from the profile under 1.x.

### Added

- **`Model\AuthorityBinding`** — the spec's authority-binding derivation
  algorithm, implemented as specified rather than as a string prefix test.
  2026-08-25 makes this a MUST: a platform validates every declared `schema`
  URL and **rejects the entity outright** when the URL's label-reversed host
  is not the entity name or a label-aligned prefix of it. A capability that
  fails is treated as not present and never activated, silently. The
  implementation handles the cases the spec calls out explicitly — userinfo
  decoys (`https://ucp.dev@evil.example/x.json` has host `evil.example`),
  IP-literal and single-label hosts, and near-miss namespaces
  (`com.examplecorp` vs `com.example`). `angeo:ucp:validate` runs it over
  every capability, service binding and payment handler.

- **Ed25519 (OKP) and P-384 signing keys.**
  `angeo:ucp:keys:generate --type=ed25519|es384|es256`. The spec RECOMMENDS
  Ed25519 for signers opting into Web Bot Auth interop on HTTP transport;
  ES256 remains the universal baseline and is what AP2 mandate signing
  requires, so the command points that out when you generate an Ed25519 key
  alone. Ed25519 uses ext-sodium (bundled with PHP 7.2+) and degrades with a
  clear message when it is unavailable — it is a `suggest`, not a hard
  requirement.

- **RFC 7638 JWK thumbprint as the default `kid`.** The spec REQUIRES the
  thumbprint form for any key used in dual-audience Web Bot Auth signatures,
  so that `UCP-Agent` and `Signature-Agent` lookups resolve the same key. The
  1.x `angeo-ucp-YYYY-xxxxxxxx` kid quietly broke that. `--kid` overrides.
  The implementation is checked against the spec's own published example key.

- **Zero-downtime key rotation.** `angeo:ucp:keys:generate --add` appends to
  `keys[]` instead of replacing it, so a new key can be published, signing
  moved over, and only then the old entry removed. Under 1.x every generate
  replaced the single stored key, which failed any signature still in flight
  against the old kid. The command also refuses to publish a duplicate kid,
  since consumers resolve keys by kid.

- **`dev.ucp.shopping.permalink`** (new root capability in 2026-08-25). Hands
  an agent a real buyable storefront URL instead of an API transaction.
  Unlike every other toggle, this one needs no endpoint module — a stock
  Magento cart URL already satisfies it — so it is safe to enable on any
  store. `config.endpoint` defaults to `{baseUrl}/checkout/cart`.

- **`dev.ucp.shopping.buyer_consent`** (new extension in 2026-08-25).
  Extends cart and/or checkout; pruned when neither parent is declared, like
  every other extension. The admin comment is explicit that it should only be
  enabled once checkout actually records the consent it receives.

- **MCP bindings now declare their OpenRPC `schema`.** 1.4.0 emitted an MCP
  binding with `endpoint` but no schema, leaving agents no machine-readable
  description of the MCP surface; the spec's own business-profile example
  declares one on every transport that defines it.

### Fixed

- **Ed25519 keys were unpublishable.** `Config` required `kid/kty/crv/x/y` on
  every stored JWK, so an OKP key — which correctly has no `y` — was
  discarded with a warning. Validation now follows
  `profile.json#/$defs/jwk_public_key`: `kid` and `kty` are always required,
  EC additionally needs `crv/x/y`, OKP needs `crv/x`, and the kty/crv/alg
  vocabularies are treated as OPEN, so an unrecognised key type is published
  rather than dropped (the spec requires that an unsupported key affect only
  the signature referencing it, never the whole profile).

- **`alg`/`crv` consistency is now enforced.** The new schema pins ES256 to
  P-256, ES384 to P-384 and EdDSA to Ed25519; a hand-edited JWK with a
  mismatched pair failed schema validation with no local warning.

- **Private-material stripping extended** to the `oth` and `k` JWK members,
  which the 2026-08-25 key schema also forbids. 1.x stripped only
  `d/p/q/dp/dq/qi`.

- **`spec` URLs are no longer authority-bound.** 1.x rejected any `dev.ucp.*`
  entity whose `spec` URL was not on `ucp.dev`. The new spec is explicit that
  documentation is off the machine trust path: a `spec` URL MUST be https but
  MAY be served from any host. The check now applies to `schema` only, which
  is where the binding actually belongs.

### CI

- `UCP_SPEC_TAG` bumped to `v2026-08-25`.
- `dev/schema-validation/validate.py` follows the schema move:
  `source/discovery/profile_schema.json#/$defs/business_profile` was deleted
  and replaced by `source/schemas/profile.json#/$defs/business_schema`. Both
  layouts are probed so the validator still works if the tag is rolled back.
- Two new fixtures: a permalink-only profile (the realistic shape for a store
  with no endpoint module) and a multi-parent extension profile exercising
  the array form of `extends`. The full fixture publishes both an Ed25519 and
  an ES256 key, mirroring the spec's own example.

### Upgrade notes

1. `composer require angeo/module-ucp:^2.0 && bin/magento setup:upgrade`
2. Run `bin/magento angeo:ucp:validate` and fix anything it reports.
3. **If any platform integrates against your current profile**, freeze a copy
   of the 2026-04-08 profile as a static file and declare it under
   *Advanced → Supported Versions*:
   `{"2026-04-08": "https://yourstore.com/ucp/profiles/2026-04-08.json"}`.
   This module serves only the version it advertises, so the older profile
   has to be hosted by you.
4. Purge `/.well-known/ucp` from any CDN or Varnish in front of the store.
5. Existing ES256 keys keep working unchanged and are simply re-published
   under `keys[]`. Their kid is left alone — regenerating with a thumbprint
   kid is only necessary if you intend to use Web Bot Auth.

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
  configured, the emitted profile is byte-identical to 1.3.0.

## [1.3.0] - 2026-07-03

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
