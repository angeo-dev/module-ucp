<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Keys;

/**
 * Formats public keys as JWKs per RFC 7517 / RFC 7518 / RFC 8037.
 *
 * UCP 2026-08-25 defines TWO well-known key types for the profile's
 * `keys[]` array (schemas/profile.json#/$defs/jwk_public_key):
 *
 *   EC  — ECDSA P-256 (ES256) or P-384 (ES384). The universal baseline,
 *         and the type AP2 mandate signing uses.
 *   OKP — EdDSA Ed25519. RECOMMENDED for signers opting into Web Bot Auth
 *         interop on HTTP transport.
 *
 * The schema pins `alg` to `crv` for every well-known curve, so this class
 * always emits the matching pair rather than leaving `alg` to the caller.
 *
 * Changes in 2.0.0:
 *  - P-384 and Ed25519 support (1.x hard-rejected anything but P-256).
 *  - RFC 7638 JWK thumbprint computation. The spec REQUIRES the kid to be
 *    the thumbprint for keys used in dual-audience Web Bot Auth signatures,
 *    so that UCP-Agent and Signature-Agent lookups resolve the same key.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7638
 * @see https://datatracker.ietf.org/doc/html/rfc8037
 */
class JwkFormatter
{
    /** Curve name in OpenSSL -> [JWK crv, alg, coordinate byte width]. */
    private const EC_CURVES = [
        'prime256v1' => ['P-256', 'ES256', 32],
        'secp384r1'  => ['P-384', 'ES384', 48],
    ];

    /**
     * Format the public half of an EC key as a JWK.
     *
     * @param string $publicPem PEM-encoded EC public key.
     * @param string $kid       Key identifier, or '' to derive the RFC 7638
     *                          thumbprint from the key itself.
     * @return array<string, string> JWK: kid, kty, crv, x, y, use, alg.
     * @throws \RuntimeException on invalid input.
     */
    public function publicKeyToJwk(string $publicPem, string $kid = ''): array
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
                'Public key is not an EC key. Use ed25519PublicKeyToJwk() for OKP keys.'
            );
        }

        $curveName = (string) ($details['ec']['curve_name'] ?? '');
        if (!isset(self::EC_CURVES[$curveName])) {
            throw new \RuntimeException(sprintf(
                'Unsupported curve "%s". UCP well-known EC curves are P-256 '
                . '(prime256v1) and P-384 (secp384r1).',
                $curveName !== '' ? $curveName : 'unknown'
            ));
        }

        [$crv, $alg, $width] = self::EC_CURVES[$curveName];

        $x = $details['ec']['x'] ?? '';
        $y = $details['ec']['y'] ?? '';

        if (!is_string($x) || !is_string($y) || $x === '' || $y === '') {
            throw new \RuntimeException('Public key is missing x/y coordinates.');
        }

        $jwk = [
            'kty' => 'EC',
            'crv' => $crv,
            'x'   => $this->base64UrlEncode($this->normaliseCoordinate($x, 'x', $width, $crv)),
            'y'   => $this->base64UrlEncode($this->normaliseCoordinate($y, 'y', $width, $crv)),
        ];

        return $this->finalise($jwk, $kid, $alg);
    }

    /**
     * Format a raw 32-byte Ed25519 public key as an OKP JWK (RFC 8037 §2).
     *
     * @param string $rawPublicKey 32 raw bytes, as returned by
     *                             sodium_crypto_sign_publickey().
     * @param string $kid          Key identifier, or '' for the RFC 7638
     *                             thumbprint.
     * @return array<string, string> JWK: kid, kty, crv, x, use, alg.
     * @throws \RuntimeException on invalid input.
     */
    public function ed25519PublicKeyToJwk(string $rawPublicKey, string $kid = ''): array
    {
        if (strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException(sprintf(
                'Ed25519 public key must be exactly %d bytes, got %d.',
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                strlen($rawPublicKey)
            ));
        }

        // OKP keys carry no `y` — the 1.x validator required one and would
        // have discarded every Ed25519 key.
        $jwk = [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x'   => $this->base64UrlEncode($rawPublicKey),
        ];

        return $this->finalise($jwk, $kid, 'EdDSA');
    }

    /**
     * RFC 7638 JWK Thumbprint (SHA-256, base64url).
     *
     * Hashes the canonical form: ONLY the required members, lexicographically
     * ordered, no whitespace. For EC that is {crv,kty,x,y}; for OKP {crv,kty,x}.
     * Any other member — kid, use, alg — must be excluded, otherwise the
     * thumbprint no longer matches what a Web Bot Auth verifier computes.
     *
     * @param array<string, string> $jwk
     */
    public function thumbprint(array $jwk): string
    {
        $kty = $jwk['kty'] ?? '';

        $canonical = match ($kty) {
            'EC'  => ['crv' => $jwk['crv'] ?? '', 'kty' => 'EC', 'x' => $jwk['x'] ?? '', 'y' => $jwk['y'] ?? ''],
            'OKP' => ['crv' => $jwk['crv'] ?? '', 'kty' => 'OKP', 'x' => $jwk['x'] ?? ''],
            default => throw new \RuntimeException(sprintf(
                'Cannot compute an RFC 7638 thumbprint for key type "%s".',
                is_string($kty) && $kty !== '' ? $kty : 'unknown'
            )),
        };

        $json = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return $this->base64UrlEncode(hash('sha256', $json, true));
    }

    /**
     * Attach kid/use/alg in the member order the spec examples use.
     *
     * @param array<string, string> $jwk
     * @return array<string, string>
     */
    private function finalise(array $jwk, string $kid, string $alg): array
    {
        $kid = trim($kid);
        if ($kid === '') {
            $kid = $this->thumbprint($jwk);
        }

        return ['kid' => $kid] + $jwk + ['use' => 'sig', 'alg' => $alg];
    }

    /**
     * Normalise an EC coordinate to exactly the curve's fixed byte width.
     *
     * Left-pads short values (OpenSSL strips leading zero bytes). Rejects
     * over-long values rather than truncating, because a truncated
     * coordinate silently produces a key nobody can verify against.
     *
     * @throws \RuntimeException if the coordinate is longer than expected.
     */
    private function normaliseCoordinate(string $raw, string $name, int $width, string $crv): string
    {
        $len = strlen($raw);

        if ($len > $width) {
            throw new \RuntimeException(sprintf(
                'EC coordinate "%s" is %d bytes; expected at most %d for %s. '
                . 'The supplied key may not be a valid %s key.',
                $name,
                $len,
                $width,
                $crv,
                $crv
            ));
        }

        if ($len < $width) {
            return str_repeat("\0", $width - $len) . $raw;
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
