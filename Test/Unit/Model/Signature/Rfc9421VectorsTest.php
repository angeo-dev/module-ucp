<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Signature\JwkVerifier;
use Angeo\Ucp\Model\Signature\SignatureBase;
use Angeo\Ucp\Model\Signature\StructuredFields;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test vectors from RFC 9421, Appendix B (keys B.1.3 and B.1.4, examples
 * B.2.4 and B.2.6). Published values, not values this module produced: the
 * base must match byte for byte and the published signatures must verify.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9421#appendix-B
 */
#[CoversClass(JwkVerifier::class)]
#[CoversClass(SignatureBase::class)]
class Rfc9421VectorsTest extends TestCase
{
    /** B.1.3 test-key-ecc-p256 (public part). */
    private const KEY_ECC_P256 = [
        'kty' => 'EC',
        'crv' => 'P-256',
        'kid' => 'test-key-ecc-p256',
        'x'   => 'qIVYZVLCrPZHGHjP17CTW0_-D9Lfw0EkjqF7xB4FivA',
        'y'   => 'Mc4nN9LTDOBhfoUeg8Ye9WedFRhnZXZJA12Qp0zZ6F0',
    ];

    /** B.1.4 test-key-ed25519 (public part). */
    private const KEY_ED25519 = [
        'kty' => 'OKP',
        'crv' => 'Ed25519',
        'kid' => 'test-key-ed25519',
        'x'   => 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs',
    ];

    /** B.2.6 Signature-Input value (label sig-b26), unwrapped. */
    private const B26_PARAMS = '("date" "@method" "@path" "@authority" "content-type" "content-length")'
        . ';created=1618884473;keyid="test-key-ed25519"';

    /** B.2.6 signature base, unwrapped. */
    private const B26_BASE = "\"date\": Tue, 20 Apr 2021 02:07:55 GMT\n"
        . "\"@method\": POST\n"
        . "\"@path\": /foo\n"
        . "\"@authority\": example.com\n"
        . "\"content-type\": application/json\n"
        . "\"content-length\": 18\n"
        . '"@signature-params": ' . self::B26_PARAMS;

    private const B26_SIGNATURE = 'wqcAqbmYJ2ji2glfAMaRy4gruYYnx2nEFN2HN6jrnDnQCK1u02Gb04v9EDgwUPiu4A0w6vuQv5lIp5WPpBKRCw==';

    /** B.2.4 signature base (response, ecdsa-p256-sha256), unwrapped. */
    private const B24_BASE = "\"@status\": 200\n"
        . "\"content-type\": application/json\n"
        . "\"content-digest\": sha-512=:mEWXIS7MaLRuGgxOBdODa3xqM1XdEvxoYhvlCFJ41QJgJc4GTsPp29l5oGX69wWdXymyU0rjJuahq4l5aGgfLQ==:\n"
        . "\"content-length\": 23\n"
        . '"@signature-params": ("@status" "content-type" "content-digest" "content-length")'
        . ';created=1618884473;keyid="test-key-ecc-p256"';

    private const B24_SIGNATURE = 'wNmSUAhwb5LxtOtOpNa6W5xj067m5hFrj0XQ4fvpaCLx0NKocgPquLgyahnzDnDAUy5eCdlYUEkLIj+32oiasw==';

    #[Test]
    public function rebuilds_the_b26_signature_base_from_the_test_request(): void
    {
        $input = StructuredFields::parseInnerListDictionary('sig-b26=' . self::B26_PARAMS)['sig-b26'];
        $raw   = StructuredFields::rawMembers('sig-b26=' . self::B26_PARAMS)['sig-b26'];

        $base = (new SignatureBase())->build($this->testRequest(), $input['components'], $raw);

        self::assertSame(self::B26_BASE, $base);
    }

    #[Test]
    public function verifies_the_published_ed25519_signature(): void
    {
        $verifier = new JwkVerifier();

        self::assertSame('ed25519', $verifier->algorithmFor(self::KEY_ED25519));
        self::assertTrue($verifier->verify(self::KEY_ED25519, '', self::B26_BASE, base64_decode(self::B26_SIGNATURE)));
    }

    #[Test]
    public function verifies_the_published_ecdsa_p256_signature(): void
    {
        $verifier = new JwkVerifier();

        self::assertSame('ecdsa-p256-sha256', $verifier->algorithmFor(self::KEY_ECC_P256));
        self::assertTrue($verifier->verify(self::KEY_ECC_P256, '', self::B24_BASE, base64_decode(self::B24_SIGNATURE)));
        self::assertTrue($verifier->verify(self::KEY_ECC_P256, 'ES256', self::B24_BASE, base64_decode(self::B24_SIGNATURE)));
    }

    #[Test]
    public function one_changed_byte_fails_either_algorithm(): void
    {
        $verifier = new JwkVerifier();

        self::assertFalse($verifier->verify(self::KEY_ED25519, '', self::B26_BASE . ' ', base64_decode(self::B26_SIGNATURE)));
        self::assertFalse($verifier->verify(self::KEY_ECC_P256, '', str_replace('200', '201', self::B24_BASE), base64_decode(self::B24_SIGNATURE)));
    }

    #[Test]
    public function the_algorithm_named_must_match_the_key(): void
    {
        $verifier = new JwkVerifier();

        self::assertFalse($verifier->verify(self::KEY_ECC_P256, 'ed25519', self::B24_BASE, base64_decode(self::B24_SIGNATURE)));
        self::assertFalse($verifier->verify(self::KEY_ED25519, 'ecdsa-p256-sha256', self::B26_BASE, base64_decode(self::B26_SIGNATURE)));
        self::assertFalse($verifier->verify(['kty' => 'RSA', 'n' => 'x', 'e' => 'AQAB'], '', self::B24_BASE, 'sig'));
    }

    /**
     * RFC 9421 Appendix B.2 `test-request`.
     */
    private function testRequest(): HttpRequest
    {
        $headers = [
            'host'           => 'example.com',
            'date'           => 'Tue, 20 Apr 2021 02:07:55 GMT',
            'content-type'   => 'application/json',
            'content-digest' => 'sha-512=:WZDPaVn/7XgHaAy8pmojAkGWoRx2UFChF41A2svX+TaPm+AbwAgBWnrIiYllu7BNNyealdVLvRwEmTHWXvJwew==:',
            'content-length' => '18',
        ];

        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name) => $headers[strtolower($name)] ?? false
        );
        $request->method('getRequestUri')->willReturn('/foo?param=Value&Pet=dog');
        $request->method('getMethod')->willReturn('POST');
        $request->method('isSecure')->willReturn(true);
        $request->method('getContent')->willReturn('{"hello": "world"}');

        return $request;
    }
}
