# Changelog

All notable changes to `angeo/module-ucp` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-beta] - 2026-05-23

### Fixed

- **Critical:** `composer.json` was invalid JSON (trailing comma after
  `support.source`), preventing `composer require` from succeeding.
- `magento/module-store` is now explicitly required in `composer.json`
  and listed in `module.xml` `<sequence>`. Previously the module relied
  on transitive loading via `Magento_Backend`, which works in practice
  but fails the Magento Extension Quality Program (MEQP) checks.
- Removed dead `etc/frontend/routes.xml`. The frontend route never
  resolved /.well-known/ucp anyway (Magento frontNames cannot contain
  dots), and the custom `Angeo\Ucp\Controller\Router` dispatches the
  action directly. The leftover routes.xml was misleading.
- Removed unused imports in `Controller/WellKnown/Ucp.php`
  (`HttpResponse`, `ResponseInterface`, `ActionInterface`).

### Changed

- `Angeo\Ucp\Model\Config` now takes a `Psr\Log\LoggerInterface` as a
  third constructor argument. Throwables from `StoreManager::getStore()`
  during endpoint resolution and JSON-decode failures on stored JWKs
  are now logged at `error` / `warning` instead of swallowed silently.
- When `Config::getPublicSigningKeys()` strips private JWK fields
  (`d`, `p`, `q`, `dp`, `dq`, `qi`), a `warning` is now logged so the
  operator can see they pasted a private key by mistake.
- `Controller\WellKnown\Ucp` now also catches non-`JsonException`
  throwables from the generator and returns `500
  profile_generation_failed` instead of bubbling.
- Tagged `0.1.0-beta` rather than `0.1.0` to signal pre-stable status
  explicitly via semver pre-release tags.

### Added

- `.github/workflows/ci.yml` — the matrix PHP 8.2/8.3/8.4 GitHub Actions
  workflow that the README badge points to. PHPStan job is included
  with `continue-on-error` until the full Magento source tree is
  available in CI.
- `SECURITY.md` — vulnerability reporting policy and the private-key
  custodianship model promoted out of README prose into a discoverable
  file.
- `ext-openssl` and `ext-json` now declared as composer requirements.
- Two new ConfigTest cases covering the new logging behaviour
  (private-field warning, store-resolution-throw error).

### Notes

- **v0.1.0-beta is profile-only.** Enabling a capability adds it to
  the advertised profile but does NOT implement the corresponding REST
  endpoints. Real catalog/cart/checkout endpoints land in later releases.
- Private signing keys are intentionally not stored in the database.
  The `keys:generate` command prints the private PEM to stdout once;
  operators are responsible for placing it in `app/etc/env.php` or a
  secrets manager.

## [0.1.0] - 2026-05-21 *(yanked — composer.json was invalid JSON)*

### Added

- Spec-compliant `/.well-known/ucp` profile generator at UCP protocol
  version `2026-04-08`.
- Custom router (`Angeo\Ucp\Controller\Router`) that maps `/.well-known/ucp`
  to a Magento controller without abusing `frontName` (dots are not allowed
  in Magento frontNames).
- ECDSA P-256 signing key generator with JWK output:
  `bin/magento angeo:ucp:keys:generate`.
- Profile validator: `bin/magento angeo:ucp:validate [--json]`.
- Admin configuration under Stores → Configuration → Angeo → UCP with
  per-store-view scoping for capability toggles.
- Declared capabilities: `dev.ucp.shopping.{catalog,cart,checkout,order}`
  and `dev.ucp.common.identity_linking`.
- Cache headers per UCP spec: `Cache-Control: public, max-age=300`.
- PHPUnit test suite (PHP 8.2, 8.3, 8.4).
- GitHub Actions CI matrix.
