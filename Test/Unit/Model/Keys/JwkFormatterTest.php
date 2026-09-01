<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Keys;

use Angeo\Ucp\Model\Keys\JwkFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JwkFormatter::class)]
#[RequiresPhpExtension('openssl')]
final class JwkFormatterTest extends TestCase
{
    private JwkFormatter $formatter;

    /**
     * Fresh keypair generated once per test class for performance.
     *
     * @var array{private: string, public: string}
     */
    private static array $keypair;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        if ($resource === false) {
            self::markTestSkipped('OpenSSL cannot generate prime256v1 keys in this environment.');
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        self::$keypair = [
            'private' => (string) $privatePem,
            'public'  => (string) $details['key'],
        ];
    }

    protected function setUp(): void
    {
        $this->formatter = new JwkFormatter();
    }

    #[Test]
    public function jwk_has_required_fields(): void
    {
        $jwk = $this->formatter->publicKeyToJwk(self::$keypair['public'], 'test-kid');

        self::assertSame('test-kid', $jwk['kid']);
        self::assertSame('EC', $jwk['kty']);
        self::assertSame('P-256', $jwk['crv']);
        self::assertSame('sig', $jwk['use']);
        self::assertSame('ES256', $jwk['alg']);
        self::assertArrayHasKey('x', $jwk);
        self::assertArrayHasKey('y', $jwk);
    }

    #[Test]
    public function jwk_coordinates_are_base64url_encoded_without_padding(): void
    {
        $jwk = $this->formatter->publicKeyToJwk(self::$keypair['public'], 'kid');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['x']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $jwk['y']);
        self::assertStringNotContainsString('=', $jwk['x']);
        self::assertStringNotContainsString('=', $jwk['y']);
    }

    #[Test]
    public function jwk_coordinates_decode_to_32_bytes_for_p256(): void
    {
        $jwk = $this->formatter->publicKeyToJwk(self::$keypair['public'], 'kid');

        $xBytes = self::base64UrlDecode($jwk['x']);
        $yBytes = self::base64UrlDecode($jwk['y']);

        self::assertSame(32, strlen($xBytes), 'P-256 x coordinate must be 32 bytes.');
        self::assertSame(32, strlen($yBytes), 'P-256 y coordinate must be 32 bytes.');
    }

    #[Test]
    public function jwk_does_not_leak_private_key_material(): void
    {
        $jwk = $this->formatter->publicKeyToJwk(self::$keypair['public'], 'kid');

        self::assertArrayNotHasKey('d', $jwk);
    }

    #[Test]
    public function rejects_invalid_pem(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to parse public key/');

        $this->formatter->publicKeyToJwk('not a valid PEM string', 'kid');
    }

    #[Test]
    public function rejects_non_ec_key(): void
    {
        $rsa = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($rsa === false) {
            self::markTestSkipped('Cannot generate RSA test key.');
        }
        $details   = openssl_pkey_get_details($rsa);
        $rsaPublic = (string) $details['key'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an EC key/');

        $this->formatter->publicKeyToJwk($rsaPublic, 'kid');
    }

    #[Test]
    public function empty_kid_derives_the_rfc7638_thumbprint(): void
    {
        // 1.x threw on an empty kid. 2.0.0 derives the RFC 7638 JWK
        // thumbprint instead, because the spec REQUIRES the thumbprint form
        // for keys used in dual-audience Web Bot Auth signatures.
        $jwk = $this->formatter->publicKeyToJwk(self::$keypair['public'], '');

        self::assertNotSame('', $jwk['kid']);
        self::assertSame($this->formatter->thumbprint($jwk), $jwk['kid']);
    }

    #[Test]
    public function thumbprint_matches_the_published_spec_example(): void
    {
        // Test vector: the Ed25519 key in the business-profile example of
        // https://ucp.dev/2026-08-25/specification/overview/ — its kid is
        // stated to be the RFC 7638 thumbprint of the key itself.
        $thumbprint = $this->formatter->thumbprint([
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x'   => 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs',
        ]);

        self::assertSame('poqkLGiymh_W0uP6PZFw-dvez3QJT5SolqXBCW38r0U', $thumbprint);
    }

    #[Test]
    public function thumbprint_excludes_optional_members(): void
    {
        // RFC 7638 hashes ONLY the required members in lexicographic order.
        // Including kid/use/alg would produce a digest no Web Bot Auth
        // verifier could reproduce.
        $bare = ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs'];
        $rich = $bare + ['kid' => 'something-else', 'use' => 'sig', 'alg' => 'EdDSA'];

        self::assertSame(
            $this->formatter->thumbprint($bare),
            $this->formatter->thumbprint($rich)
        );
    }

    #[Test]
    public function ed25519_public_key_formats_as_an_okp_jwk_without_y(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('ext-sodium is not available.');
        }

        $publicKey = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        $jwk = $this->formatter->ed25519PublicKeyToJwk($publicKey);

        self::assertSame('OKP', $jwk['kty']);
        self::assertSame('Ed25519', $jwk['crv']);
        self::assertSame('EdDSA', $jwk['alg']);
        // OKP keys carry no y coordinate — 1.x's validation wrongly demanded one.
        self::assertArrayNotHasKey('y', $jwk);
    }

    #[Test]
    public function ed25519_rejects_a_wrong_length_public_key(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('ext-sodium is not available.');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be exactly 32 bytes/');

        $this->formatter->ed25519PublicKeyToJwk(str_repeat("\0", 31));
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded  = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url string in test.');
        }
        return $decoded;
    }
}
