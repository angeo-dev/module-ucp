<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Api;

/**
 * Generates the UCP business profile served at /.well-known/ucp.
 *
 * Profile structure follows the UCP spec at
 * https://ucp.dev/latest/specification/overview/#business-profile
 *
 * @api
 */
interface ProfileGeneratorInterface
{
    /**
     * Build the complete UCP profile as an associative array ready for
     * JSON encoding. The structure includes:
     *
     *  - ucp.version       Protocol version (date format YYYY-MM-DD)
     *  - ucp.services      Declared service bindings (REST transport in v0.1.0)
     *  - ucp.capabilities  Declared root capabilities and extensions
     *  - signing_keys      Public keys in JWK format (ECDSA P-256)
     *
     * @return array<string, mixed>
     */
    public function generate(): array;
}
