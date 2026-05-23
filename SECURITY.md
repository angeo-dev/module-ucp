# Security Policy

## Supported versions

`angeo/module-ucp` is pre-1.0. Only the latest tag receives security fixes.
Once 1.0.0 ships, the latest minor will be supported for security fixes for
six months past the next minor's release.

## Reporting a vulnerability

Email **info@angeo.dev** with the subject line `[security] module-ucp`.

Please include:

- Module version (output of `bin/magento module:status Angeo_Ucp`).
- Magento and PHP version.
- Steps to reproduce, or proof-of-concept.
- Whether you believe the issue is publicly known.

I aim to acknowledge within 72 hours and ship a patched release within
14 days for confirmed high-severity issues. Coordinated disclosure is
appreciated — please do not open a public GitHub issue for security
problems.

## Private key custodianship model

UCP signing requires an ECDSA P-256 private key. This module is designed
so the private key **never touches the database**:

- `bin/magento angeo:ucp:keys:generate` generates a new keypair, writes
  only the **public JWK** to `core_config_data`, and prints the
  **private PEM to stdout exactly once**.
- Operators are responsible for placing the private PEM in
  `app/etc/env.php` under `'ucp' => ['signing_keys' => [...]]`, or in
  an external secrets manager their server reads at runtime.
- The private PEM is not persisted anywhere by this module.

If you accidentally paste a private JWK (with field `d`) into the admin
"Public JWK" textarea:

- `Config::getPublicSigningKeys()` strips private fields (`d`, `p`, `q`,
  `dp`, `dq`, `qi`) before the profile is served — defence in depth.
- A `warning` is logged to `var/log/system.log` so the mistake is
  visible to operators.
- The affected keypair **MUST be considered compromised**: rotate it
  immediately with `bin/magento angeo:ucp:keys:generate --force` and
  update `app/etc/env.php` with the new PEM.

## Out of scope

- Reports requiring physical access to the merchant server.
- Reports about Magento core or unrelated third-party modules.
- Theoretical attacks without a demonstrated impact.
