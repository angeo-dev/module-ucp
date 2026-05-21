# Changelog

All notable changes to `angeo/module-ucp` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-05-21

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

### Notes

- **v0.1.0 is profile-only.** Enabling a capability adds it to the
  advertised profile but does NOT implement the corresponding REST
  endpoints. Real catalog/cart/checkout endpoints land in later releases.
- Private signing keys are intentionally not stored in the database.
  The `keys:generate` command prints the private PEM to stdout once;
  operators are responsible for placing it in `app/etc/env.php` or a
  secrets manager.
