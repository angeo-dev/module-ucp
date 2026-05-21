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
     * Fresh keypair generated once per test class for performance —
     * key generation is the slow part.
     *
     * @var array{private: string, public: string}
     */
    private static array $keypair;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($resource === false) {
            self::markTestSkipped('OpenSSL cannot generate prime256v1 keys in this environment.');
        }

        openssl_pkey_export($resource, $privatePem);
        $details = openssl_pkey_get_details($resource);

        self::$keypair = [
            'private' => (string) $privatePem,
            'public' => (string) $details['key'],
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

        // Base64URL alphabet: A–Z, a–z, 0–9, -, _; no =, +, /.
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

        // RFC 7518 private fields for EC keys.
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
        // Generate an RSA key — different kty, should be refused.
        $rsa = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        if ($rsa === false) {
            self::markTestSkipped('Cannot generate RSA test key.');
        }
        $details = openssl_pkey_get_details($rsa);
        $rsaPublicPem = (string) $details['key'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an EC key/');

        $this->formatter->publicKeyToJwk($rsaPublicPem, 'kid');
    }

    private static function base64UrlDecode(string $value): string
    {
        $padded = $value . str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url string in test.');
        }
        return $decoded;
    }
}
