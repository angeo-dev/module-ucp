# Changelog

All notable changes to `angeo/module-ucp` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1-beta] - 2026-05-23

### Fixed

- Removed the static `"version"` field from `composer.json`. Packagist
  now reads the version from the git tag, which is the canonical
  Composer practice and eliminates the risk of the manifest version
  drifting from the released tag.

### Changed

- Installation now requires the explicit `@beta` stability qualifier:
  `composer require angeo/module-ucp:^0.1@beta`. This is documented in
  the README and scopes the stability exception to this package only.
- README updated: roadmap row, install instructions, and a note that
  `0.1.0` is yanked.
- composer.json description updated to reference `v0.1.x` rather than
  a single point release, since this is the line label.

### Added

- `extra.branch-alias` mapping `dev-main` to `0.1.x-dev` for users who
  want to track the development branch via Composer.

### Notes

- **All v0.1.x releases are profile-only.** Enabling a capability adds
  it to the advertised profile but does NOT implement the corresponding
  REST endpoints. Real catalog/cart/checkout endpoints land in 0.2.0+.

## [0.1.0-beta] - 2026-05-23

### Fixed

- **Critical:** `composer.json` was invalid JSON (trailing comma after
  `support.source`), preventing `composer require` from succeeding.
- `magento/module-store` is now explicitly required in `composer.json`
  and listed in `module.xml` `<sequence>`. Previously the module relied
  on transitive loading via `Magento_Backend`, which works in practice
  but fails the Magento Extension Quality Program (MEQP) checks.
- Removed dead `etc/frontend/routes.xml`. The frontend route never
  resolved `/.well-known/ucp` anyway (Magento frontNames cannot contain
  dots), and the custom `Angeo\Ucp\Controller\Router` dispatches the
  action directly.
- Removed unused imports in `Controller/WellKnown/Ucp.php`.

### Changed

- `Angeo\Ucp\Model\Config` now takes a `Psr\Log\LoggerInterface` as a
  third constructor argument. Throwables from `StoreManager::getStore()`
  during endpoint resolution and JSON-decode failures on stored JWKs
  are now logged at `error` / `warning` instead of swallowed silently.
- When `Config::getPublicSigningKeys()` strips private JWK fields
  (`d`, `p`, `q`, `dp`, `dq`, `qi`), a `warning` is now logged so the
  operator can see they pasted a private key by mistake.
- `Controller\WellKnown\Ucp` now also catches non-`JsonException`
  throwables from the generator and returns `500 profile_generation_failed`
  instead of bubbling.

### Added

- `SECURITY.md` — vulnerability reporting policy and the private-key
  custodianship model.
- `ext-openssl` and `ext-json` now declared as composer requirements.
- Two new ConfigTest cases covering the new logging behaviour.

## [0.1.0] - 2026-05-21 *(yanked — composer.json was invalid JSON)*

### Added

- Initial public release. Spec-compliant `/.well-known/ucp` profile
  generator at UCP protocol version `2026-04-08`, custom router,
  ECDSA P-256 key generator, profile validator CLI, admin
  configuration, PHPUnit suite, GitHub Actions CI.

This tag is treated as yanked because the published `composer.json`
contained a trailing comma that made it invalid JSON. Composer cannot
install it. Use `0.1.1-beta` or later.
