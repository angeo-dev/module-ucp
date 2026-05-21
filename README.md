[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-ucp.svg)](https://packagist.org/packages/angeo/module-ucp)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php->%3D8.2-8892BF.svg)](https://php.net)
[![CI](https://github.com/angeo-dev/module-ucp/actions/workflows/ci.yml/badge.svg)](https://github.com/angeo-dev/module-ucp/actions)

# angeo/module-ucp

**Spec-compliant Universal Commerce Protocol (UCP) profile generator for Magento 2.**

Generates `/.well-known/ucp` at protocol version `2026-04-08` with ECDSA P-256 signing keys, declared capabilities, and proper cache headers — the discovery layer AI agents fetch first when interacting with your store.

> **v0.1.0 is the profile-only release.** It exposes a valid UCP profile to AI platforms. The actual REST endpoints for catalog, cart, checkout and order land in later versions. Enabling a capability here adds it to the advertised profile but does NOT implement the matching endpoint — leave capabilities disabled in production until the matching endpoint module is installed.

---

## What it does

- Serves a spec-compliant UCP profile at `https://yourstore.com/.well-known/ucp`
- Declares the `dev.ucp.shopping` REST service binding
- Lets you toggle which capabilities to advertise: catalog, cart, checkout, order, identity_linking
- Generates ECDSA P-256 signing keys (JWK + PEM) via CLI
- Returns correct `Cache-Control: public, max-age=300` headers
- Returns `404 ucp_not_advertised` when the module is disabled — never a misleading empty profile

---

## Requirements

| Requirement | Version |
| --- | --- |
| Magento | 2.4.7+ |
| PHP | 8.2 or 8.3 or 8.4 |
| OpenSSL extension | enabled |

---

## Installation

```bash
composer require angeo/module-ucp
bin/magento module:enable Angeo_Ucp
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## Quick start

### 1. Generate signing keys

```bash
bin/magento angeo:ucp:keys:generate
```

This generates an ECDSA P-256 keypair, saves the **public** JWK to config, and prints the **private** PEM to stdout exactly once. Copy the private PEM into `app/etc/env.php`:

```php
'ucp' => [
    'signing_keys' => [
        'angeo-ucp-2026-abcd' => '<PEM contents>',
    ],
],
```

### 2. Enable the profile in admin

Stores → Configuration → Angeo → UCP → General → **Advertise UCP Profile: Yes**.

### 3. Verify

```bash
curl -s https://yourstore.com/.well-known/ucp | python3 -m json.tool
```

Expected output:

```json
{
    "ucp": {
        "version": "2026-04-08",
        "services": {
            "dev.ucp.shopping": [
                {
                    "version": "2026-04-08",
                    "spec": "https://ucp.dev/2026-04-08/specification/overview",
                    "transport": "rest",
                    "endpoint": "https://yourstore.com/rest/V1/ucp",
                    "schema": "https://ucp.dev/2026-04-08/services/shopping/rest.openapi.json"
                }
            ]
        },
        "capabilities": {}
    },
    "signing_keys": [
        {
            "kid": "angeo-ucp-2026-abcd",
            "kty": "EC",
            "crv": "P-256",
            "x": "...",
            "y": "...",
            "use": "sig",
            "alg": "ES256"
        }
    ]
}
```

### 4. Validate (optional)

```bash
bin/magento angeo:ucp:validate --json
```

Prints a green pass if the profile is well-formed; non-zero exit on validation failure (useful as a cron healthcheck).

---

## Admin configuration

| Path | Field | Purpose |
| --- | --- | --- |
| General → Advertise UCP Profile | Yes/No | Master switch for `/.well-known/ucp` |
| Capabilities → Catalog | Yes/No | Advertise `dev.ucp.shopping.catalog` |
| Capabilities → Cart | Yes/No | Advertise `dev.ucp.shopping.cart` |
| Capabilities → Checkout | Yes/No | Advertise `dev.ucp.shopping.checkout` |
| Capabilities → Order | Yes/No | Advertise `dev.ucp.shopping.order` |
| Capabilities → Identity Linking | Yes/No | Advertise `dev.ucp.common.identity_linking` |
| Transport → REST Endpoint URL | URL | Override the default `{baseUrl}/rest/V1/ucp` |
| Keys → Public JWK | textarea | Populated by `keys:generate` |

All capability and transport settings are scoped to the **store view** — multi-store deployments can advertise different profiles per storefront.

---

## CLI commands

| Command | Purpose |
| --- | --- |
| `bin/magento angeo:ucp:keys:generate [--force]` | Generate a new P-256 keypair; print private PEM once, save public JWK to config |
| `bin/magento angeo:ucp:validate [--json]` | Validate the generated profile structure; optionally print the JSON |

---

## Architecture

```
HTTP request: GET /.well-known/ucp
        │
        ▼
Angeo\Ucp\Controller\Router  (sortOrder=22, runs before CMS router)
        │
        ▼
Angeo\Ucp\Controller\WellKnown\Ucp  (HttpGetActionInterface)
        │
        ▼
Angeo\Ucp\Model\ProfileGenerator   ←  Angeo\Ucp\Model\Config
        │                                     │
        │                                     └── reads core_config_data
        ▼
JSON response with:
  - Content-Type: application/json
  - Cache-Control: public, max-age=300
  - X-UCP-Version: 2026-04-08
```

---

## Security model

- **Private keys are never stored in the database.** The `keys:generate` command prints them once to stdout. Operators are responsible for placing them in `app/etc/env.php` or a secrets manager.
- **`Config::getPublicSigningKeys()` strips private JWK fields** (`d`, `p`, `q`, `dp`, `dq`, `qi`) as defence-in-depth before serialization, in case private material ever ends up in config by mistake.
- **HTTPS-only by design.** The UCP spec requires HTTPS for `/.well-known/ucp`; this module's audit check refuses a non-HTTPS endpoint URL.
- **Cache headers comply with the spec** (`public`, `max-age >= 60`). The module sends `max-age=300`.

---

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite=unit
```

Three test classes cover the critical paths:

- `ProfileGeneratorTest` — protocol version, namespace correctness, JSON encoding, capability toggling
- `JwkFormatterTest` — RFC 7518 base64url coordinate encoding, 32-byte P-256 width, private-field rejection
- `ConfigTest` — store-base-url fallback, private-key sanitization

CI runs the matrix PHP 8.2 / 8.3 / 8.4 on every push.

---

## Roadmap

| Version | Scope |
| --- | --- |
| **0.1.0** *(current)* | Profile generator, signing keys, admin UI, CLI, tests, CI |
| **0.2.0** | `dev.ucp.shopping.catalog` real endpoint (search + lookup) backed by `Magento_Catalog` |
| **0.3.0** | `UCP-Agent` header parsing, platform profile fetching, capability intersection |
| **0.4.0** | RFC 9421 HTTP Message Signatures for outgoing responses |
| **0.5.0** | Cart capability |
| **1.0.0** | Production-ready full UCP shopping vertical |

Roadmap will shift as the UCP specification evolves.

---

## License

MIT License — see [LICENSE](LICENSE)

Maintained by **[Ievgenii Gryshkun](https://angeo.dev)** · [info@angeo.dev](mailto:info@angeo.dev)
