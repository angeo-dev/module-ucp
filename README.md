# Angeo UCP — `/.well-known/ucp` for Magento 2

Publishes a [Universal Commerce Protocol](https://ucp.dev) business profile at
`/.well-known/ucp` so AI shopping agents (Google/Gemini, ChatGPT, etc.) can
discover your store's commerce capabilities.

- Profile generated per UCP spec **2026-08-25** (current release) and
  validated in CI against the **official JSON Schemas** from the spec
  repository (`schemas/profile.json#business_schema`)
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

> Declaring a capability advertises it; it does not implement it. Enable a
> toggle only when the matching endpoint is live, or agents will send requests
> that fail. **Permalink is the exception** — it is satisfied by a stock
> Magento cart URL, so it is safe to turn on anywhere.

## Security

- The profile is **public and unauthenticated** by design (per the UCP spec).
  Never put secrets, internal URLs, or admin contacts in it.
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
