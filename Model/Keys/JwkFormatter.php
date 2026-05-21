<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Keys;

/**
 * Converts ECDSA P-256 keypairs from OpenSSL into JWK (JSON Web Key) format
 * per RFC 7517 + RFC 7518.
 *
 * UCP signing keys use ES256 (ECDSA on P-256 with SHA-256) by default.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7517
 * @see https://datatracker.ietf.org/doc/html/rfc7518#section-6.2
 */
class JwkFormatter
{
    private const COORDINATE_BYTES = 32; // P-256 uses 32-byte coordinates.

    /**
     * Format the public half of a P-256 EC key as a JWK.
     *
     * @param string $publicPem PEM-encoded EC public key.
     * @param string $kid       Key identifier to embed.
     * @return array<string, string> JWK fields: kid, kty, crv, x, y, use, alg.
     */
    public function publicKeyToJwk(string $publicPem, string $kid): array
    {
        $key = openssl_pkey_get_public($publicPem);
        if ($key === false) {
            throw new \RuntimeException(
                'Failed to parse public key PEM: ' . openssl_error_string()
            );
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec'])) {
            throw new \RuntimeException(
                'Public key is not an EC key. UCP signing keys require ECDSA P-256.'
            );
        }

        if (($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
            throw new \RuntimeException(sprintf(
                'Unsupported curve "%s". UCP v0.1.0 requires prime256v1 (P-256).',
                $details['ec']['curve_name'] ?? 'unknown'
            ));
        }

        $x = $details['ec']['x'] ?? '';
        $y = $details['ec']['y'] ?? '';

        if (!is_string($x) || !is_string($y) || $x === '' || $y === '') {
            throw new \RuntimeException('Public key is missing x/y coordinates.');
        }

        return [
            'kid' => $kid,
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => $this->base64UrlEncode($this->padCoordinate($x)),
            'y' => $this->base64UrlEncode($this->padCoordinate($y)),
            'use' => 'sig',
            'alg' => 'ES256',
        ];
    }

    /**
     * Left-pad an EC coordinate to the curve's fixed byte width so that
     * different keys produce stable, comparable JWK encodings.
     */
    private function padCoordinate(string $raw): string
    {
        if (strlen($raw) >= self::COORDINATE_BYTES) {
            return $raw;
        }

        return str_repeat("\0", self::COORDINATE_BYTES - strlen($raw)) . $raw;
    }

    /**
     * Base64URL encoding per RFC 4648 §5 — no padding, URL-safe alphabet.
     */
    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
