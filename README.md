# Angeo UCP — `/.well-known/ucp` for Magento 2

[![CI](https://github.com/angeo-dev/module-ucp/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-ucp/actions/workflows/ci.yml)
[![UCP official checks](https://img.shields.io/github/actions/workflow/status/angeo-dev/module-ucp/ucp-official.yml?branch=main&label=UCP%20official%20checks)](https://github.com/angeo-dev/module-ucp/actions/workflows/ucp-official.yml)
[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-ucp)](https://packagist.org/packages/angeo/module-ucp)
[![Downloads](https://img.shields.io/packagist/dt/angeo/module-ucp)](https://packagist.org/packages/angeo/module-ucp)
[![PHP](https://img.shields.io/badge/PHP-8.1%20%E2%80%93%208.5-777bb4)](composer.json)
[![Magento](https://img.shields.io/badge/Magento-2.4.6%20%E2%80%93%202.4.9-f26322)](composer.json)
[![Mage-OS Directory](https://img.shields.io/badge/Mage--OS-Extension%20Directory-2d7d9a)](https://directory.mage-os.org/packages/angeo/module-ucp/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![UCP spec](https://img.shields.io/badge/UCP%20spec-2026--08--25-blue)](https://ucp.dev)

Publishes a [Universal Commerce Protocol](https://ucp.dev) business profile at
`/.well-known/ucp` so AI shopping agents (Google/Gemini, ChatGPT, etc.) can
discover your store's commerce capabilities.

- Profile generated per UCP spec **2026-08-25** (current release), following
  the **official JSON Schemas** from the spec repository
  (`schemas/profile.json#business_schema`)
- **Authority binding enforced locally.** 2026-08-25 makes it a MUST that a
  platform reject any entity whose `schema` host does not match its namespace
  — and it does so *silently*. `angeo:ucp:validate` runs the spec's own
  derivation algorithm so you find out before a platform does
- Signing keys published in the canonical top-level **`keys[]`** JWK Set
  (RFC 7517), **public keys only**. ES256, ES384 and **Ed25519** supported,
  with RFC 7638 thumbprint key ids for Web Bot Auth interop, and
  zero-downtime rotation via `--add`
- Multi-transport service bindings: REST out of the box, optional **MCP
  binding** — extensible to further transports and services via
  `Api\ServiceBindingProviderInterface` and the di.xml provider pool
- Served by a **PHP controller** — correct `Content-Type: application/json`,
  CORS, and cache headers, with **no nginx/Apache changes**

## How the endpoint is served

`/.well-known/ucp` is delivered by a controller, not a static file, because the
UCP spec requires `Content-Type: application/json` **and** CORS headers
(`Access-Control-Allow-Origin: *`) — neither of which a static file without an
extension can provide without editing the web server.

| Component | Role |
|-----------|------|
| `Controller\Router` | Custom router matching the exact path `/.well-known/ucp` and dispatching the action. Registered in `etc/di.xml` via `RouterList` (sortOrder 22, before the CMS router). Returns `null` for any other path. |
| `Controller\WellKnown\Ucp` | Builds and returns the profile as JSON with `Content-Type: application/json`, CORS, `Cache-Control: public, max-age=300`, and hardening headers. Answers `OPTIONS` preflight with `204` + CORS headers. Returns **404** when the module is disabled (the site simply does not advertise UCP). Public, no auth — as the spec requires. |
| `Model\ProfileGenerator` | Builds the spec-2026-08-25 profile (services, capabilities, extensions, payment handlers, supported versions, public keys). |
| `Model\Spec` | Single source of truth for every spec/schema URL the profile publishes. |
| `Model\AuthorityBinding` | The spec's `schema`-URL authority-binding derivation algorithm. |
| `Model\Keys\KeyGenerator` / `JwkFormatter` | Generates EC and Ed25519 keys, formats the **public** half as a JWK, and computes RFC 7638 thumbprints. |

### Why this reaches PHP without web-server changes

The stock Magento nginx config ends its main location with
`try_files $uri $uri/ /index.php$is_args$args`. A request for `/.well-known/ucp`
with **no matching static file** falls through to `index.php`, where the custom
router dispatches it. The official Magento nginx sample has no
`location ~ /\.` deny rule, so the dot-segment is not blocked.

> If your host added a custom `location ~ /\. { deny all; }` rule it blocks all
> dot-paths, and the profile then needs a one-line nginx allow for
> `^~ /.well-known/`. The stock config does not have this problem.

> Do **not** leave a static file at `pub/.well-known/ucp`: nginx would serve it
> first (as `application/octet-stream`, no CORS) and the controller would never
> run.

## Inbound signature verification

The profile publishes keys. The verifier uses them: RFC 9421 signatures on
requests *arriving* at your UCP endpoints, by the spec's identity-resolution
algorithm.

This direction is the one the spec puts a MUST on — a business rejects a
request whose counterparty profile cannot be fetched or fails validation.

**Stores → Configuration → Angeo UCP → Request Security:**

| Mode | Signed & valid | Signed & invalid | Unsigned |
|---|---|---|---|
| `disabled` (default) | served | served | served |
| `optional` | served | **401/400** | served |
| `required` | served | **401/400** | **401** |

Start in `optional`. An invalid signature is refused there too — only a
*missing* one is tolerated — so you get real enforcement while you find out,
from the log, which agents actually sign.

What the verifier expects (UCP 2026-08-25):

- `UCP-Agent: profile="https://…"` — the key comes from that profile's
  `keys[]`. Without `UCP-Agent`, a `Signature-Agent` member keyed by the
  signature label with `type=jwks_uri`.
- No `alg` parameter is needed: the algorithm comes from the key
  (ES256, ES384, Ed25519). If `alg` is sent, it must match the key.
- Covered: `@method`, `@authority`, `@path`, `@query` when there is a query,
  `content-digest` and `content-type` when there is a body, and `ucp-agent`,
  `signature-agent`, `idempotency-key` when present.
- `tag="web-bot-auth"`: `created` and `expires` required, window ≤ 24 h,
  `keyid` = RFC 7638 thumbprint.
- `@authority` must be one of your store hosts (*Check Signed Host*, on by
  default).

Failures answer with the spec's codes: `signature_missing`,
`signature_invalid`, `key_not_found` (401), `digest_mismatch`,
`algorithm_unsupported` (400). The log says which part failed; the response
does not.

> **2.2.2 fixes verification for real agents.** Up to 2.2.1 the verifier read
> the whole `UCP-Agent` header as a URL and required `alg`, so every
> spec-conformant signature failed. If you switched to `optional` or
> `required` before, upgrade before you look at the log again.

Endpoint modules consume this through `Api\SignatureVerifierInterface`;
`angeo/module-ucp-catalog` and `angeo/module-ucp-checkout` do.

Not yet implemented: signing our *own* responses (RECOMMENDED for checkout
completion). Webhooks are signed by `angeo/module-ucp-checkout`.

## Official UCP checks

Every push runs the UCP project's own tools
(`.github/workflows/ucp-official.yml`):

1. Profiles built by the real `ProfileGenerator` (`Test/Conformance`) are
   validated with the official [`ucp-schema`](https://github.com/Universal-Commerce-Protocol/ucp-schema)
   CLI against the `v2026-08-25` JSON Schemas.
2. The official [conformance suite](https://github.com/Universal-Commerce-Protocol/conformance)
   runs its core tier against them (discovery profile, every spec and
   schema URL, envelope headers).
3. Weekly, a spec watch opens an issue when UCP publishes a newer release.

Run the profile part locally:

```bash
UCP_FIXTURE_DIR=build/ucp vendor/bin/phpunit --testsuite conformance
UCP_PROFILE=build/ucp/checkout.json php -S 127.0.0.1:8182 Test/Conformance/server.php
```

## Upgrading from 1.x

1.4.0 is not broken — it publishes a valid `2026-04-08` profile, and the spec
allows a business to keep serving an older version. This release adopts
`2026-08-25`, which is a package deal: the protocol version, the key field
and the capability documentation URLs all move together.

| | 1.x (`2026-04-08`) | 2.0 (`2026-08-25`) |
|---|---|---|
| Signing keys | `signing_keys[]` | **`keys[]`** (RFC 7517 JWK Set) |
| Capability `schema` | optional | **required** |
| `schema` origin check | string prefix on `ucp.dev` | **authority-binding algorithm** |
| `spec` origin check | must be `ucp.dev` | https only, any host |
| Catalog docs URL | `/specification/catalog/…` | `/specification/shopping/catalog/…` |
| Key types | P-256 only | P-256, P-384, Ed25519 |
| Key id | random | RFC 7638 thumbprint |

```bash
composer require angeo/module-ucp:^2.0
bin/magento setup:upgrade && bin/magento cache:flush
bin/magento angeo:ucp:validate
```

Existing keys keep working and are simply republished under `keys[]`; no
regeneration is needed unless you want Web Bot Auth interop. If a platform
already integrates against your current profile, freeze a copy of it and
declare it under *Advanced → Supported Versions* — this module serves only
the version it advertises.

## Install

```bash
composer require angeo/module-ucp
bin/magento module:enable Angeo_Ucp
bin/magento setup:upgrade
bin/magento cache:flush
```

Generate signing keys, then verify:

```bash
bin/magento angeo:ucp:keys:generate            # ES256, the universal baseline
curl -sI https://yourstore.com/.well-known/ucp
# HTTP/2 200
# content-type: application/json
# access-control-allow-origin: *
# cache-control: public, max-age=300
```

## CLI

| Command | Purpose |
|---------|---------|
| `angeo:ucp:keys:generate` | Generate a signing keypair. `--type=es256\|es384\|ed25519`, `--add` to publish alongside existing keys, `--kid` to override the thumbprint. |
| `angeo:ucp:validate` | Validate the generated profile against the UCP spec, including authority binding. |

### Which key type?

- **ES256** (default) — the universal baseline. Every counterparty accepts
  it, and AP2 mandate signing requires it. Start here.
- **Ed25519** — RECOMMENDED by the spec for Web Bot Auth interop on HTTP
  transport. Needs `ext-sodium`. It does *not* cover AP2, so if you sign
  mandates, publish both.
- **ES384** — for deployments with a stricter curve policy.

### Rotating without downtime

A key is not effectively revoked until it is **absent** from `keys[]` — which
also means it is not published until it is present. So rotate in that order:

```bash
bin/magento angeo:ucp:keys:generate --add   # publish the new key
# move signing over to the new kid, let caches expire
bin/magento angeo:ucp:keys:generate --force # drop everything but the newest
```

## Configuration

**Stores → Configuration → Angeo UCP** — enable the module and declare which
capabilities your store supports: catalog search/lookup, cart, checkout,
order, identity linking, **permalink**, plus the fulfillment, discount and
**buyer consent** extensions, payment handlers, and supported protocol
versions.

> Since 2.2.0, a toggle alone does not advertise cart, checkout, order,
> fulfillment, discount or buyer consent. They appear in the profile only when
> an installed module serves them:
>
> | Capability | Served by |
> |---|---|
> | `dev.ucp.shopping.catalog.search` / `.lookup` | [`angeo/module-ucp-catalog`](https://github.com/angeo-dev/module-ucp-catalog) (not gated) |
> | `dev.ucp.shopping.checkout` + `.discount` + `.fulfillment` | [`angeo/module-ucp-checkout`](https://github.com/angeo-dev/module-ucp-checkout) 1.0+ (MCP tools: [`angeo/module-ucp-checkout-mcp`](https://github.com/angeo-dev/module-ucp-checkout-mcp)) |
> | `dev.ucp.shopping.order` (Get Order + webhooks) | [`angeo/module-ucp-checkout`](https://github.com/angeo-dev/module-ucp-checkout) 1.1+ |
> | `dev.ucp.shopping.permalink` | a stock Magento cart URL (not gated) |
> | cart, buyer consent | not available yet |
>
> `bin/magento angeo:ucp:validate` lists toggles that are on but have no
> effect. A module registers what it serves through the `implementations`
> argument of `Angeo\Ucp\Model\Config` in its `di.xml`.

**REST endpoint.** Leave *Transport → REST Endpoint URL* empty to advertise
`{baseUrl}/ucp/v1`, where the catalog and checkout modules answer. Before
2.2.0 the default was `{baseUrl}/rest/V1/ucp`, which nothing served.

## Security

- The profile is **public and unauthenticated** by design (per the UCP spec).
  Never put secrets, internal URLs, or admin contacts in it.
- Agent profiles are fetched from URLs that requests supply, so every fetch
  goes through `Model\Network\PublicAddressGuard`: https, port 443, every
  resolved address global (no loopback, private, link-local, CGNAT or
  IPv4-in-IPv6 tricks), connection pinned to the checked address, no
  redirects, 128 KiB and 5 s limits. Extra ports can be allowed in di.xml
  (`allowedPorts`).
- Only **public** keys are published. The private key never leaves the server;
  `ProfileGenerator` reads public keys only (`Config::getPublicKeys()`), and
  any private JWK member (`d`, `p`, `q`, `dp`, `dq`, `qi`, `oth`, `k`) found in
  stored config is stripped before serving, with a warning telling you to
  rotate. See `SECURITY.md`.
- The endpoint sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  and `Referrer-Policy: no-referrer`.
- Rate-limiting is the operator's responsibility (reverse proxy / WAF).
- If a CDN/Varnish fronts the site, the `Cache-Control` header lets it cache the
  profile; purge `/.well-known/ucp` after rotating keys or changing config.
