<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Keys;

/**
 * Generates signing keypairs for the UCP profile's `keys[]` array.
 *
 * Supported types (both are well-known in schemas/profile.json at spec tag
 * v2026-08-25):
 *
 *   es256   ECDSA P-256. The universal baseline — every counterparty
 *           accepts it, and AP2 mandate signing requires it.
 *   es384   ECDSA P-384, for deployments with a stricter curve policy.
 *   ed25519 EdDSA Ed25519, RECOMMENDED for Web Bot Auth interop on HTTP
 *           transport. Needs ext-sodium (bundled with PHP since 7.2).
 *
 * Private keys are NEVER persisted by this module. The CLI prints the PEM
 * once; the operator places it in app/etc/env.php or a secrets manager,
 * outside the database and outside version control.
 *
 * Changes in 2.0.0:
 *  - kid defaults to the RFC 7638 JWK thumbprint instead of a random
 *    `angeo-ucp-YYYY-xxxxxxxx` string. The spec REQUIRES the thumbprint form
 *    for any key used in dual-audience Web Bot Auth signatures so that
 *    UCP-Agent and Signature-Agent lookups resolve the same key; a random
 *    kid quietly breaks that. A custom kid can still be supplied.
 *  - Ed25519 and P-384 generation.
 */
class KeyGenerator
{
    public const TYPE_ES256   = 'es256';
    public const TYPE_ES384   = 'es384';
    public const TYPE_ED25519 = 'ed25519';

    public const TYPES = [self::TYPE_ES256, self::TYPE_ES384, self::TYPE_ED25519];

    /** Key type -> OpenSSL curve name. */
    private const EC_CURVE = [
        self::TYPE_ES256 => 'prime256v1',
        self::TYPE_ES384 => 'secp384r1',
    ];

    public function __construct(
        private readonly JwkFormatter $jwkFormatter
    ) {
    }

    /**
     * Generate a new keypair and return both halves.
     *
     * @param string $type One of self::TYPES.
     * @param string $kid  Custom key identifier, or '' for the RFC 7638
     *                     thumbprint (recommended).
     * @return array{kid: string, type: string, alg: string, crv: string,
     *               private_pem: string, jwk: array<string, string>}
     * @throws \RuntimeException on unsupported type or crypto failure.
     */
    public function generate(string $type = self::TYPE_ES256, string $kid = ''): array
    {
        $type = strtolower(trim($type));

        return match ($type) {
            self::TYPE_ES256, self::TYPE_ES384 => $this->generateEc($type, $kid),
            self::TYPE_ED25519                 => $this->generateEd25519($kid),
            default => throw new \RuntimeException(sprintf(
                'Unsupported key type "%s". Supported: %s.',
                $type,
                implode(', ', self::TYPES)
            )),
        };
    }

    /**
     * @return array{kid: string, type: string, alg: string, crv: string,
     *               private_pem: string, jwk: array<string, string>}
     */
    private function generateEc(string $type, string $kid): array
    {
        if (!extension_loaded('openssl')) {
            throw new \RuntimeException(
                'The openssl extension is required to generate UCP EC signing keys. '
                . 'Enable it in your php.ini and restart the web server.'
            );
        }

        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => self::EC_CURVE[$type],
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

        $jwk = $this->jwkFormatter->publicKeyToJwk((string) $details['key'], $kid);

        return [
            'kid'         => $jwk['kid'],
            'type'        => $type,
            'alg'         => $jwk['alg'],
            'crv'         => $jwk['crv'],
            'private_pem' => (string) $privatePem,
            'jwk'         => $jwk,
        ];
    }

    /**
     * Ed25519 via ext-sodium. OpenSSL's PHP binding cannot generate Ed25519
     * keys, and sodium is bundled with PHP 7.2+, so this is the portable path.
     *
     * The exported "PEM" is a PKCS#8 wrapper built around the raw seed, so
     * the operator can store it in env.php in the same shape as an EC key.
     *
     * @return array{kid: string, type: string, alg: string, crv: string,
     *               private_pem: string, jwk: array<string, string>}
     */
    private function generateEd25519(string $kid): array
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException(
                'Ed25519 key generation requires ext-sodium, which is bundled with '
                . 'PHP 7.2+ but appears to be disabled on this server. Either enable '
                . 'it, or generate an ES256 key instead (--type=es256) — ES256 is the '
                . 'universal baseline and every UCP counterparty accepts it.'
            );
        }

        $keypair   = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        // libsodium's secret key is seed||public; RFC 8032 / PKCS#8 stores
        // only the 32-byte seed.
        $seed = substr($secretKey, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);

        $jwk = $this->jwkFormatter->ed25519PublicKeyToJwk($publicKey, $kid);

        $privatePem = $this->ed25519SeedToPkcs8Pem($seed);

        // Wipe the private material from memory as soon as it is encoded.
        sodium_memzero($secretKey);
        sodium_memzero($seed);
        sodium_memzero($keypair);

        return [
            'kid'         => $jwk['kid'],
            'type'        => self::TYPE_ED25519,
            'alg'         => 'EdDSA',
            'crv'         => 'Ed25519',
            'private_pem' => $privatePem,
            'jwk'         => $jwk,
        ];
    }

    /**
     * Wrap a raw 32-byte Ed25519 seed in the PKCS#8 PrivateKeyInfo structure
     * defined by RFC 8410 §7, then PEM-encode it.
     *
     * The DER prefix is fixed for Ed25519, so it is written literally rather
     * than pulled in as an ASN.1 dependency:
     *   30 2e                          SEQUENCE (46 bytes)
     *     02 01 00                     INTEGER version 0
     *     30 05 06 03 2b 65 70         SEQUENCE { OID 1.3.101.112 (Ed25519) }
     *     04 22 04 20 <32-byte seed>   OCTET STRING { OCTET STRING seed }
     */
    private function ed25519SeedToPkcs8Pem(string $seed): string
    {
        $der = hex2bin('302e020100300506032b657004220420') . $seed;

        return "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";
    }
}
