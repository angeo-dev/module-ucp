# Angeo UCP — `/.well-known/ucp` for Magento 2

Publishes a [Universal Commerce Protocol](https://ucp.dev) business profile at
`/.well-known/ucp` so AI shopping agents (Google/Gemini, ChatGPT, etc.) can
discover your store's commerce capabilities.

- Profile generated per UCP spec **2026-04-08**
- Served by a **PHP controller** — correct `Content-Type: application/json`,
  CORS, and cache headers, with **no nginx/Apache changes**
- ECDSA P-256 signing keys, JWK-formatted, **public keys only** in the profile

## How the endpoint is served

`/.well-known/ucp` is delivered by a controller, not a static file, because the
UCP spec requires `Content-Type: application/json` **and** CORS headers
(`Access-Control-Allow-Origin: *`) — neither of which a static file without an
extension can provide without editing the web server.

| Component | Role |
|-----------|------|
| `Controller\Router` | Custom router matching the exact path `/.well-known/ucp` and dispatching the action. Registered in `etc/di.xml` via `RouterList` (sortOrder 22, before the CMS router). Returns `null` for any other path. |
| `Controller\WellKnown\Ucp` | Builds and returns the profile as JSON with `Content-Type: application/json`, CORS, `Cache-Control: public, max-age=300`, and hardening headers. Returns **404** when the module is disabled (the site simply does not advertise UCP). Public, no auth — as the spec requires. |
| `Model\ProfileGenerator` | Builds the spec-2026-04-08 profile (services, capabilities, extensions, payment handlers, supported versions, public signing keys). |
| `Model\Keys\KeyGenerator` / `JwkFormatter` | Generates ECDSA P-256 keys and formats the **public** half as a JWK. |

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

## Install

```bash
composer require angeo/module-ucp
bin/magento module:enable Angeo_Ucp
bin/magento setup:upgrade
bin/magento cache:flush
```

Generate signing keys, then verify:

```bash
bin/magento angeo:ucp:keys:generate
curl -sI https://yourstore.com/.well-known/ucp
# HTTP/2 200
# content-type: application/json
# access-control-allow-origin: *
# cache-control: public, max-age=300
```

## CLI

| Command | Purpose |
|---------|---------|
| `bin/magento angeo:ucp:keys:generate` | Generate / rotate the ECDSA P-256 signing key pair. |
| `bin/magento angeo:ucp:validate` | Validate the generated profile against the UCP spec. |

## Configuration

**Stores → Configuration → Angeo UCP** — enable the module and declare which
capabilities your store supports (catalog search/lookup, cart, checkout, order,
identity linking, fulfillment, discount), payment handlers, and supported
protocol versions.

## Security

- The profile is **public and unauthenticated** by design (per the UCP spec).
  Never put secrets, internal URLs, or admin contacts in it.
- Only **public** signing keys are published. The private key never leaves the
  server; `ProfileGenerator` reads public keys only (`getPublicSigningKeys()`).
  See `SECURITY.md`.
- The endpoint sends `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  and `Referrer-Policy: no-referrer`.
- Rate-limiting is the operator's responsibility (reverse proxy / WAF).
- If a CDN/Varnish fronts the site, the `Cache-Control` header lets it cache the
  profile; purge `/.well-known/ucp` after rotating keys or changing config.
