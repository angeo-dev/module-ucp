<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

/**
 * Test-only signer. Builds RFC 9421 signature bases BY HAND, line by line,
 * so the tests do not reuse the production SignatureBase and cannot agree
 * with it by construction.
 */
class SigningFixture
{
    /**
     * Fresh ES256 key pair.
     *
     * @return array{jwk: array<string, string>, private: \OpenSSLAsymmetricKey}
     */
    public static function ecKey(string $kid = 'agent-es256'): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new \RuntimeException('Could not create an EC key.');
        }
        $ec = openssl_pkey_get_details($key)['ec'];

        return [
            'jwk' => [
                'kty' => 'EC',
                'crv' => 'P-256',
                'kid' => $kid,
                'use' => 'sig',
                'x'   => self::b64u(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
                'y'   => self::b64u(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            ],
            'private' => $key,
        ];
    }

    /**
     * Fresh Ed25519 key pair.
     *
     * @return array{jwk: array<string, string>, secret: string}
     */
    public static function edKey(string $kid = 'agent-ed25519'): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'jwk' => [
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'kid' => $kid,
                'x'   => self::b64u(sodium_crypto_sign_publickey($pair)),
            ],
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * Sign a base with either key type and return the raw signature bytes
     * (r||s for ECDSA, as RFC 9421 requires).
     *
     * @param array<string, mixed> $key From ecKey() or edKey().
     */
    public static function sign(array $key, string $base): string
    {
        if (isset($key['secret'])) {
            return sodium_crypto_sign_detached($base, $key['secret']);
        }

        if (!openssl_sign($base, $der, $key['private'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed.');
        }

        return self::derToRaw($der, 32);
    }

    /**
     * Signature base from explicit lines plus the params text.
     *
     * @param array<int, string> $lines `"name": value` lines, in order.
     */
    public static function base(array $lines, string $params): string
    {
        $lines[] = '"@signature-params": ' . $params;

        return implode("\n", $lines);
    }

    public static function b64u(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function derToRaw(string $der, int $width): string
    {
        $offset = 2;
        if (ord($der[1]) & 0x80) {
            $offset += ord($der[1]) & 0x7F;
        }

        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $length  = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $out    .= str_pad(ltrim($integer, "\0"), $width, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $out;
    }
}
