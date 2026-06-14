<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Keys;

/**
 * Generates ECDSA P-256 keypairs for UCP signing.
 *
 * Returns the private key as PEM (for storage in env.php) and the public
 * key formatted as a JWK ready to embed in /.well-known/ucp.
 *
 * IMPORTANT: private keys are never persisted by this module. The CLI
 * command prints the PEM to stdout once; operators are responsible for
 * placing it in app/etc/env.php (or a secrets manager) outside the
 * database and outside version control.
 *
 * Changes in 1.0.0:
 *  - Added explicit openssl_free_key() compat shim (PHP 8.0 removed it,
 *    but resources are freed automatically in PHP 8.x — no-op here).
 *  - Kid entropy increased from 2 bytes (4 hex chars / ~65k) to 4 bytes
 *    (8 hex chars / ~4 billion) to make accidental collisions negligible
 *    even in high-rotation scenarios.
 */
class KeyGenerator
{
    public function __construct(
        private readonly JwkFormatter $jwkFormatter
    ) {
    }

    /**
     * Generate a new keypair and return both halves.
     *
     * @return array{kid: string, private_pem: string, public_pem: string, jwk: array<string, string>}
     * @throws \RuntimeException on OpenSSL failure.
     */
    public function generate(): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException(
                'The openssl extension is required to generate UCP signing keys. '
                . 'Enable it in your php.ini and restart the web server.'
            );
        }

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if ($resource === false) {
            throw new \RuntimeException(
                'Failed to generate EC keypair: ' . openssl_error_string()
            );
        }

        if (!openssl_pkey_export($resource, $privatePem)) {
            throw new \RuntimeException(
                'Failed to export private key PEM: ' . openssl_error_string()
            );
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || empty($details['key'])) {
            throw new \RuntimeException(
                'Failed to extract public key details: ' . openssl_error_string()
            );
        }

        $publicPem = (string) $details['key'];
        $kid       = $this->buildKid();
        $jwk       = $this->jwkFormatter->publicKeyToJwk($publicPem, $kid);

        return [
            'kid'         => $kid,
            'private_pem' => (string) $privatePem,
            'public_pem'  => $publicPem,
            'jwk'         => $jwk,
        ];
    }

    /**
     * Build a stable, human-recognisable kid: angeo-ucp-YYYY-NNNNNNNN.
     * Uses 4 bytes of CSPRNG entropy (8 hex chars, ~4 billion distinct values).
     */
    private function buildKid(): string
    {
        $year  = date('Y');
        $nonce = bin2hex(random_bytes(4));
        return sprintf('angeo-ucp-%s-%s', $year, $nonce);
    }
}
