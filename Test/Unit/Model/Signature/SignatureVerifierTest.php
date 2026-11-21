<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Keys\JwkFormatter;
use Angeo\Ucp\Model\Signature\AuthorityPolicy;
use Angeo\Ucp\Model\Signature\JwkVerifier;
use Angeo\Ucp\Model\Signature\ProfileFetcher;
use Angeo\Ucp\Model\Signature\SignatureBase;
use Angeo\Ucp\Model\Signature\SignatureVerifier;
use Angeo\Ucp\Model\Signature\VerificationResult;
use Magento\Framework\App\Request\Http as HttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * End-to-end verification of signed requests shaped as the UCP 2026-08-25
 * spec shapes them: `UCP-Agent: profile="…"`, no `alg` parameter, the
 * algorithm taken from the key. Every request here is signed by hand
 * (SigningFixture), not through the production SignatureBase.
 */
#[CoversClass(SignatureVerifier::class)]
class SignatureVerifierTest extends TestCase
{
    private const PROFILE = 'https://platform.example/.well-known/ucp';
    private const HOST    = 'shop.example';
    private const PATH    = '/ucp/v1/checkout-sessions';
    private const BODY    = '{"line_items":[{"item":{"id":"24-MB02"},"quantity":1}]}';
    private const IDEM    = '550e8400-e29b-41d4-a716-446655440000';

    /** Covered set of a default UCP request signature (spec example). */
    private const COMPONENTS = '("@method" "@authority" "@path" "ucp-agent" "idempotency-key" "content-digest" "content-type")';

    /** @var array<string, array<string, mixed>> profile URL => profile */
    private array $profiles = [];

    /** @var array<int, array{0: string, 1: bool}> */
    private array $fetches = [];

    private bool $ownAuthority = true;

    // ── the 2.2.2 regressions ───────────────────────────────────────────

    #[Test]
    public function verifies_a_spec_shaped_request_es256(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify($this->signed($key, 'profile="' . self::PROFILE . '"'));

        self::assertTrue($result->isVerified(), $result->reason);
        self::assertSame(self::PROFILE, $result->agentProfileUrl);
        self::assertSame('agent-es256', $result->keyId);
        self::assertSame([[self::PROFILE, false]], $this->fetches, 'Only the profile member is fetched.');
    }

    #[Test]
    public function verifies_with_a_version_parameter_on_ucp_agent(): void
    {
        // The shape the official conformance suite and module-ucp-checkout use.
        $key   = SigningFixture::ecKey();
        $agent = 'profile="' . self::PROFILE . '"; version="2026-08-25"';
        $this->publish(self::PROFILE, $key['jwk']);

        self::assertTrue($this->verifier()->verify($this->signed($key, $agent))->isVerified());
    }

    #[Test]
    public function verifies_ed25519_without_alg(): void
    {
        $key = SigningFixture::edKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify($this->signed($key, 'profile="' . self::PROFILE . '"'));

        self::assertTrue($result->isVerified(), $result->reason);
    }

    #[Test]
    public function accepts_an_alg_that_matches_the_key(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $request = $this->signed($key, 'profile="' . self::PROFILE . '"', ';alg="ecdsa-p256-sha256"');

        self::assertTrue($this->verifier()->verify($request)->isVerified());
    }

    #[Test]
    public function rejects_an_alg_that_contradicts_the_key(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $request = $this->signed($key, 'profile="' . self::PROFILE . '"', ';alg="ed25519"');
        $result  = $this->verifier()->verify($request);

        self::assertFalse($result->isVerified());
        self::assertSame(VerificationResult::CODE_SIGNATURE_INVALID, $result->code);
    }

    #[Test]
    public function an_unknown_alg_is_algorithm_unsupported_with_400(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify(
            $this->signed($key, 'profile="' . self::PROFILE . '"', ';alg="rsa-pss-sha512"')
        );

        self::assertSame(VerificationResult::CODE_ALGORITHM_UNSUPPORTED, $result->code);
        self::assertSame(400, $result->httpStatus());
    }

    // ── tampering ────────────────────────────────────────────────────────

    #[Test]
    public function a_changed_body_is_digest_mismatch_with_400(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify(
            $this->signed($key, 'profile="' . self::PROFILE . '"', '', ['body' => '{"line_items":[]}'])
        );

        self::assertFalse($result->isVerified());
        self::assertSame(VerificationResult::CODE_DIGEST_MISMATCH, $result->code);
        self::assertSame(400, $result->httpStatus());
    }

    #[Test]
    public function a_changed_path_does_not_verify(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify(
            $this->signed($key, 'profile="' . self::PROFILE . '"', '', ['path' => '/ucp/v1/checkout-sessions/chk_x/complete'])
        );

        self::assertFalse($result->isVerified());
        self::assertSame(VerificationResult::CODE_SIGNATURE_INVALID, $result->code);
        self::assertSame(401, $result->httpStatus());
    }

    #[Test]
    public function a_swapped_ucp_agent_does_not_verify(): void
    {
        // The signer covered its own profile; an attacker points UCP-Agent
        // at a profile that holds a key with the same kid.
        $key   = SigningFixture::ecKey();
        $other = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);
        $this->publish('https://evil.example/.well-known/ucp', $other['jwk']);

        $result = $this->verifier()->verify(
            $this->signed($key, 'profile="' . self::PROFILE . '"', '', ['agent_on_wire' => 'profile="https://evil.example/.well-known/ucp"'])
        );

        self::assertFalse($result->isVerified());
    }

    #[Test]
    public function a_signature_that_does_not_cover_ucp_agent_is_skipped(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $result = $this->verifier()->verify($this->signed(
            $key,
            'profile="' . self::PROFILE . '"',
            '',
            ['components' => '("@method" "@authority" "@path" "idempotency-key" "content-digest" "content-type")']
        ));

        self::assertFalse($result->isVerified());
        self::assertSame([], $this->fetches, 'Coverage is checked before any fetch.');
    }

    #[Test]
    public function a_signature_for_another_host_is_refused(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);
        $this->ownAuthority = false;

        $result = $this->verifier()->verify($this->signed($key, 'profile="' . self::PROFILE . '"'));

        self::assertFalse($result->isVerified());
        self::assertSame([], $this->fetches);
    }

    // ── keys ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_unknown_kid_refreshes_once_then_is_key_not_found(): void
    {
        $key = SigningFixture::ecKey('rotated-away');
        $this->publish(self::PROFILE, SigningFixture::ecKey('current')['jwk']);

        $result = $this->verifier()->verify($this->signed($key, 'profile="' . self::PROFILE . '"'));

        self::assertSame(VerificationResult::CODE_KEY_NOT_FOUND, $result->code);
        self::assertSame([[self::PROFILE, false], [self::PROFILE, true]], $this->fetches);
    }

    #[Test]
    public function a_key_marked_for_encryption_is_not_used(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, ['use' => 'enc'] + $key['jwk']);

        self::assertFalse($this->verifier()->verify($this->signed($key, 'profile="' . self::PROFILE . '"'))->isVerified());
    }

    // ── headers ──────────────────────────────────────────────────────────

    #[Test]
    public function no_signature_headers_is_unsigned(): void
    {
        $result = $this->verifier()->verify($this->request(['UCP-Agent' => 'profile="' . self::PROFILE . '"']));

        self::assertTrue($result->isUnsigned());
        self::assertSame(VerificationResult::CODE_SIGNATURE_MISSING, $result->code);
    }

    #[Test]
    public function signature_input_without_signature_is_signature_missing(): void
    {
        $result = $this->verifier()->verify($this->request([
            'Signature-Input' => 'sig1=' . self::COMPONENTS . ';keyid="k"',
        ]));

        self::assertFalse($result->isVerified());
        self::assertFalse($result->isUnsigned());
        self::assertSame(VerificationResult::CODE_SIGNATURE_MISSING, $result->code);
    }

    #[Test]
    public function a_bad_first_signature_does_not_hide_a_good_second_one(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $good = $this->signed($key, 'profile="' . self::PROFILE . '"', '', ['label' => 'sig2']);
        $headers = $good->headers;
        $headers['Signature-Input'] = 'sig1=' . self::COMPONENTS . ';keyid="nope", ' . $headers['Signature-Input'];
        $headers['Signature'] = 'sig1=:' . base64_encode(str_repeat("\1", 64)) . ':, ' . $headers['Signature'];

        $result = $this->verifier()->verify($this->request($headers));

        self::assertTrue($result->isVerified(), $result->reason);
    }

    // ── Web Bot Auth shape ───────────────────────────────────────────────

    #[Test]
    public function verifies_a_dual_audience_web_bot_auth_signature(): void
    {
        $key = SigningFixture::edKey();
        $kid = (new JwkFormatter())->thumbprint($key['jwk']);
        $jwk = ['kid' => $kid] + $key['jwk'];
        $this->publish(self::PROFILE, $jwk);

        $result = $this->verifier()->verify($this->webBotAuth($key, $kid, time() - 10, time() + 3600));

        self::assertTrue($result->isVerified(), $result->reason);
    }

    #[Test]
    public function web_bot_auth_without_expires_is_refused(): void
    {
        $key = SigningFixture::edKey();
        $kid = (new JwkFormatter())->thumbprint($key['jwk']);
        $this->publish(self::PROFILE, ['kid' => $kid] + $key['jwk']);

        self::assertFalse($this->verifier()->verify($this->webBotAuth($key, $kid, time(), null))->isVerified());
    }

    #[Test]
    public function web_bot_auth_with_a_window_over_24_hours_is_refused(): void
    {
        $key = SigningFixture::edKey();
        $kid = (new JwkFormatter())->thumbprint($key['jwk']);
        $this->publish(self::PROFILE, ['kid' => $kid] + $key['jwk']);

        self::assertFalse(
            $this->verifier()->verify($this->webBotAuth($key, $kid, time(), time() + 2 * 86400))->isVerified()
        );
    }

    #[Test]
    public function web_bot_auth_keyid_must_be_the_thumbprint(): void
    {
        $key = SigningFixture::edKey('not-a-thumbprint');
        $this->publish(self::PROFILE, $key['jwk']);

        self::assertFalse(
            $this->verifier()->verify($this->webBotAuth($key, 'not-a-thumbprint', time(), time() + 60))->isVerified()
        );
    }

    #[Test]
    public function an_expired_signature_is_refused(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $request = $this->signed($key, 'profile="' . self::PROFILE . '"', ';created=1700000000;expires=1700000600');

        self::assertFalse($this->verifier()->verify($request)->isVerified());
    }

    #[Test]
    public function signature_agent_resolves_by_label_when_ucp_agent_is_absent(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $agent   = 'sig1="' . self::PROFILE . '";type=jwks_uri';
        $request = $this->signedWithSignatureAgent($key, $agent, 'sig1');

        self::assertTrue($this->verifier()->verify($request)->isVerified());
    }

    #[Test]
    public function signature_agent_for_another_label_does_not_resolve(): void
    {
        $key = SigningFixture::ecKey();
        $this->publish(self::PROFILE, $key['jwk']);

        $agent   = 'other="' . self::PROFILE . '";type=jwks_uri';
        $result  = $this->verifier()->verify($this->signedWithSignatureAgent($key, $agent, 'sig1'));

        self::assertSame(VerificationResult::CODE_KEY_NOT_FOUND, $result->code);
    }

    // ── fixtures ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $key
     * @param array<string, string> $opts body, path, label, components, agent_on_wire
     */
    private function signed(array $key, string $ucpAgent, string $extraParams = '', array $opts = []): RequestDouble
    {
        $label      = $opts['label'] ?? 'sig1';
        $components = $opts['components'] ?? self::COMPONENTS;
        $digest     = 'sha-256=:' . base64_encode(hash('sha256', self::BODY, true)) . ':';
        $created    = str_contains($extraParams, 'created=') ? '' : ';created=' . time();
        $params     = $components . $created . ';keyid="' . $key['jwk']['kid'] . '"' . $extraParams;

        $values = [
            '@method'         => 'POST',
            '@authority'      => self::HOST,
            '@path'           => self::PATH,
            'ucp-agent'       => $ucpAgent,
            'idempotency-key' => self::IDEM,
            'content-digest'  => $digest,
            'content-type'    => 'application/json',
        ];
        $lines = [];
        preg_match_all('/"([^"]+)"/', $components, $names);
        foreach ($names[1] as $name) {
            $lines[] = '"' . $name . '": ' . $values[$name];
        }

        $signature = SigningFixture::sign($key, SigningFixture::base($lines, $params));

        return $this->request([
            'Host'            => self::HOST,
            'Content-Type'    => 'application/json',
            'Content-Digest'  => $digest,
            'Idempotency-Key' => self::IDEM,
            'UCP-Agent'       => $opts['agent_on_wire'] ?? $ucpAgent,
            'Signature-Input' => $label . '=' . $params,
            'Signature'       => $label . '=:' . base64_encode($signature) . ':',
        ], $opts['path'] ?? self::PATH, $opts['body'] ?? self::BODY);
    }

    /**
     * The dual-audience example of the spec: UCP-Agent plus Signature-Agent,
     * `signature-agent;key="sig1"` covered, thumbprint keyid, tag.
     *
     * @param array<string, mixed> $key
     */
    private function webBotAuth(array $key, string $kid, int $created, ?int $expires): RequestDouble
    {
        $signatureAgent = 'sig1="' . self::PROFILE . '";type=jwks_uri';
        $ucpAgent       = 'profile="' . self::PROFILE . '"';
        $digest         = 'sha-256=:' . base64_encode(hash('sha256', self::BODY, true)) . ':';

        $params = '("@method" "@authority" "@path" "signature-agent";key="sig1" "ucp-agent" "idempotency-key" "content-digest" "content-type")'
            . ';keyid="' . $kid . '";created=' . $created
            . ($expires !== null ? ';expires=' . $expires : '')
            . ';tag="web-bot-auth"';

        $base = SigningFixture::base([
            '"@method": POST',
            '"@authority": ' . self::HOST,
            '"@path": ' . self::PATH,
            '"signature-agent";key="sig1": "' . self::PROFILE . '";type=jwks_uri',
            '"ucp-agent": ' . $ucpAgent,
            '"idempotency-key": ' . self::IDEM,
            '"content-digest": ' . $digest,
            '"content-type": application/json',
        ], $params);

        return $this->request([
            'Host'            => self::HOST,
            'Content-Type'    => 'application/json',
            'Content-Digest'  => $digest,
            'Idempotency-Key' => self::IDEM,
            'UCP-Agent'       => $ucpAgent,
            'Signature-Agent' => $signatureAgent,
            'Signature-Input' => 'sig1=' . $params,
            'Signature'       => 'sig1=:' . base64_encode(SigningFixture::sign($key, $base)) . ':',
        ], self::PATH, self::BODY);
    }

    /**
     * @param array<string, mixed> $key
     */
    private function signedWithSignatureAgent(array $key, string $signatureAgent, string $label): RequestDouble
    {
        $digest = 'sha-256=:' . base64_encode(hash('sha256', self::BODY, true)) . ':';
        $params = '("@method" "@authority" "@path" "signature-agent" "content-digest" "content-type")'
            . ';created=' . time() . ';keyid="' . $key['jwk']['kid'] . '"';
        $base   = SigningFixture::base([
            '"@method": POST',
            '"@authority": ' . self::HOST,
            '"@path": ' . self::PATH,
            '"signature-agent": ' . $signatureAgent,
            '"content-digest": ' . $digest,
            '"content-type": application/json',
        ], $params);

        return $this->request([
            'Host'            => self::HOST,
            'Content-Type'    => 'application/json',
            'Content-Digest'  => $digest,
            'Signature-Agent' => $signatureAgent,
            'Signature-Input' => $label . '=' . $params,
            'Signature'       => $label . '=:' . base64_encode(SigningFixture::sign($key, $base)) . ':',
        ], self::PATH, self::BODY);
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers, string $path = self::PATH, string $body = self::BODY): RequestDouble
    {
        $mock = $this->createMock(HttpRequest::class);
        $double = new RequestDouble($mock, $headers);

        $mock->method('getHeader')->willReturnCallback(static function (string $name) use ($headers) {
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return $value;
                }
            }
            return false;
        });
        $mock->method('getContent')->willReturn($body);
        $mock->method('getRequestUri')->willReturn($path);
        $mock->method('getMethod')->willReturn('POST');
        $mock->method('isSecure')->willReturn(true);

        return $double;
    }

    /**
     * @param array<string, string> $jwk
     */
    private function publish(string $url, array $jwk): void
    {
        $this->profiles[$url] = ['ucp' => ['version' => '2026-08-25'], 'keys' => [$jwk]];
    }

    private function verifier(): VerifierHarness
    {
        $fetcher = $this->getMockBuilder(ProfileFetcher::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch'])
            ->getMock();
        $fetcher->method('fetch')->willReturnCallback(function (string $url, bool $force = false): ?array {
            $this->fetches[] = [$url, $force];
            return $this->profiles[$url] ?? null;
        });

        $authority = $this->createMock(AuthorityPolicy::class);
        $authority->method('isAllowed')->willReturnCallback(
            fn (string $a): bool => $this->ownAuthority && $a === self::HOST
        );

        return new VerifierHarness(new SignatureVerifier(
            $fetcher,
            new SignatureBase(),
            new JwkVerifier(),
            new JwkFormatter(),
            $this->createMock(LoggerInterface::class),
            $authority
        ));
    }
}
