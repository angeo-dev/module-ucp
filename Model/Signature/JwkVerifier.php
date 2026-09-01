<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

/**
 * Verifies an RFC 9421 signature against a public JWK from a UCP profile.
 *
 * Algorithms, per the UCP signature-algorithm table:
 *   ecdsa-p256-sha256  EC / P-256 / ES256
 *   ecdsa-p384-sha384  EC / P-384 / ES384
 *   ed25519            OKP / Ed25519 / EdDSA
 *
 * Two details that silently break naive implementations:
 *
 *  - RFC 9421 ECDSA signatures are the RAW r||s concatenation (as in JWS),
 *    not the ASN.1 DER SEQUENCE that openssl_verify() expects. They are
 *    converted here.
 *  - The JWK must be turned into a key OpenSSL accepts. Rather than depend
 *    on an ASN.1 library, the SubjectPublicKeyInfo is assembled directly:
 *    the algorithm identifiers are constant per curve, so only the point
 *    bytes vary.
 */
class JwkVerifier
{
    /** alg => [kty, crv, OpenSSL digest, coordinate width, SPKI prefix]. */
    private const ALGORITHMS = [
        'ecdsa-p256-sha256' => ['EC', 'P-256', OPENSSL_ALGO_SHA256, 32,
            '3059301306072a8648ce3d020106082a8648ce3d03010703420004'],
        'ecdsa-p384-sha384' => ['EC', 'P-384', OPENSSL_ALGO_SHA384, 48,
            '3076301006072a8648ce3d020106052b8104002203620004'],
        'ed25519'           => ['OKP', 'Ed25519', null, 32,
            '302a300506032b6570032100'],
    ];

    /**
     * @param array<string, mixed> $jwk Public JWK from the signer's profile.
     * @param string $alg RFC 9421 algorithm identifier.
     * @param string $base Reconstructed signature base.
     * @param string $signature Raw signature bytes.
     */
    public function verify(array $jwk, string $alg, string $base, string $signature): bool
    {
        $alg = strtolower(trim($alg));
        if (!isset(self::ALGORITHMS[$alg])) {
            return false;
        }

        [$kty, $crv, $digest, $width, $prefix] = self::ALGORITHMS[$alg];

        // The key must actually be of the type the algorithm names. Without
        // this check a signer could nominate a weaker algorithm than the key
        // the profile advertises.
        if (($jwk['kty'] ?? null) !== $kty || ($jwk['crv'] ?? null) !== $crv) {
            return false;
        }

        return $alg === 'ed25519'
            ? $this->verifyEd25519($jwk, $base, $signature)
            : $this->verifyEc($jwk, $base, $signature, $digest, $width, $prefix);
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function verifyEd25519(array $jwk, string $base, string $signature): bool
    {
        if (!extension_loaded('sodium')) {
            return false;
        }

        $publicKey = $this->decode((string) ($jwk['x'] ?? ''));

        if ($publicKey === null
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
        ) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $base, $publicKey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $jwk
     */
    private function verifyEc(
        array $jwk,
        string $base,
        string $signature,
        int $digest,
        int $width,
        string $prefix
    ): bool {
        if (!extension_loaded('openssl')) {
            return false;
        }

        $x = $this->decode((string) ($jwk['x'] ?? ''));
        $y = $this->decode((string) ($jwk['y'] ?? ''));

        if ($x === null || $y === null || strlen($x) !== $width || strlen($y) !== $width) {
            return false;
        }

        // RFC 9421 ECDSA signature is r||s, each exactly `width` bytes.
        if (strlen($signature) !== $width * 2) {
            return false;
        }

        $der = $this->rawSignatureToDer(
            substr($signature, 0, $width),
            substr($signature, $width)
        );

        $pem = $this->spkiToPem((string) hex2bin($prefix) . $x . $y);

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            return false;
        }

        return openssl_verify($base, $der, $key, $digest) === 1;
    }

    /**
     * Wrap raw r and s as the ASN.1 DER SEQUENCE OpenSSL expects.
     * Each INTEGER is minimally encoded, with a leading 0x00 when the high
     * bit is set so the value is not read as negative.
     */
    private function rawSignatureToDer(string $r, string $s): string
    {
        $encode = static function (string $value): string {
            $value = ltrim($value, "\0");
            if ($value === '') {
                $value = "\0";
            }
            if (ord($value[0]) > 0x7F) {
                $value = "\0" . $value;
            }
            return "\x02" . chr(strlen($value)) . $value;
        };

        $body = $encode($r) . $encode($s);

        // Signature bodies here are always well under 128 bytes, so the
        // short-form length is correct; guard anyway.
        if (strlen($body) > 127) {
            return "\x30\x81" . chr(strlen($body)) . $body;
        }

        return "\x30" . chr(strlen($body)) . $body;
    }

    private function spkiToPem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function decode(string $base64Url): ?string
    {
        if ($base64Url === '') {
            return null;
        }

        $padded  = strtr($base64Url, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding !== 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
