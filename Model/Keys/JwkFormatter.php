<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Keys;

/**
 * Converts ECDSA P-256 public keys from OpenSSL into JWK (JSON Web Key)
 * format per RFC 7517 + RFC 7518.
 *
 * UCP signing keys use ES256 (ECDSA on P-256 with SHA-256).
 *
 * Changes in 1.0.0:
 *  - Truncation guard added: coordinates longer than 32 bytes are now
 *    rejected (previously only short coordinates were padded; an over-long
 *    coordinate from a malformed key would be silently included).
 *  - kid is validated to be a non-empty string before being embedded.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7517
 * @see https://datatracker.ietf.org/doc/html/rfc7518#section-6.2
 */
class JwkFormatter
{
    /** P-256 coordinates are exactly 32 bytes. */
    private const COORDINATE_BYTES = 32;

    /**
     * Format the public half of a P-256 EC key as a JWK.
     *
     * @param string $publicPem PEM-encoded EC public key.
     * @param string $kid       Key identifier to embed (must be non-empty).
     * @return array<string, string> JWK fields: kid, kty, crv, x, y, use, alg.
     * @throws \RuntimeException on invalid input.
     */
    public function publicKeyToJwk(string $publicPem, string $kid): array
    {
        if ($kid === '') {
            throw new \RuntimeException('JWK kid must not be empty.');
        }

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
                'Unsupported curve "%s". UCP v1.0.0 requires prime256v1 (P-256).',
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
            'x'   => $this->base64UrlEncode($this->normaliseCoordinate($x, 'x')),
            'y'   => $this->base64UrlEncode($this->normaliseCoordinate($y, 'y')),
            'use' => 'sig',
            'alg' => 'ES256',
        ];
    }

    /**
     * Normalise a P-256 coordinate to exactly COORDINATE_BYTES bytes.
     *
     * Left-pads short values (e.g. a leading-zero coordinate that OpenSSL
     * returns with the zero stripped). Rejects over-long values rather than
     * silently truncating them, because truncation would produce an invalid key.
     *
     * @throws \RuntimeException if the coordinate is longer than expected.
     */
    private function normaliseCoordinate(string $raw, string $name): string
    {
        $len = strlen($raw);

        if ($len > self::COORDINATE_BYTES) {
            throw new \RuntimeException(sprintf(
                'EC coordinate "%s" is %d bytes; expected at most %d for P-256. '
                . 'The supplied key may not be a valid P-256 key.',
                $name,
                $len,
                self::COORDINATE_BYTES
            ));
        }

        if ($len < self::COORDINATE_BYTES) {
            return str_repeat("\0", self::COORDINATE_BYTES - $len) . $raw;
        }

        return $raw;
    }

    /**
     * Base64URL encoding per RFC 4648 §5 — no padding, URL-safe alphabet.
     */
    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
