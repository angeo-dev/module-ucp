<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\AgentHeader;
use Angeo\Ucp\Model\Keys\JwkFormatter;
use Magento\Framework\App\Request\Http as HttpRequest;
use Psr\Log\LoggerInterface;

/**
 * Implements the UCP identity-resolution algorithm for inbound requests.
 *
 * A request MAY carry several signatures (RFC 9421 §4.3). Each is attempted
 * independently and the request is authenticated when AT LEAST ONE verifies;
 * a signature that cannot be processed is SKIPPED rather than treated as a
 * failure. Only when every signature has been skipped or rejected — and at
 * least one was present — does the request fail.
 *
 * Key resolution uses the default UCP mechanism: read the `profile` member
 * of the `UCP-Agent` dictionary, fetch that profile, and match `keyid`
 * against its `keys[]`. `Signature-Agent` (Web Bot Auth) is an optional
 * additive layer, used only when `UCP-Agent` is absent: the member whose key
 * equals the signature label, with `type=jwks_uri`. `cimd` and `directory`
 * are skipped rather than pretended.
 *
 * The algorithm comes from the matched key's kty/crv. The spec says `alg`
 * is NOT included in Signature-Input; if a signer sends it anyway it must
 * agree with the key.
 *
 * Covered-component enforcement is where most of the security lives: a
 * signature that does not cover the request target, the body digest, or a
 * present `ucp-agent` / `signature-agent` / `idempotency-key` header is
 * skipped, because an uncovered component is an unsigned component.
 *
 * 2.2.2 fixes two defects that made verification fail for every
 * spec-conformant signer: the raw `UCP-Agent` header was used as the URL
 * (instead of its `profile` member), and a missing `alg` parameter skipped
 * the signature.
 */
class SignatureVerifier implements SignatureVerifierInterface
{
    /** Always required. @query is added when a query string is present. */
    private const REQUIRED_DERIVED = ['@method', '@authority', '@path'];

    /** Required when the request carries a body. */
    private const REQUIRED_BODY = ['content-digest', 'content-type'];

    /** Required when present on the request (closed set per the spec). */
    private const REQUIRED_IF_PRESENT = ['ucp-agent', 'signature-agent', 'idempotency-key'];

    /** Clock drift allowed on `created` and `expires`, in seconds. */
    private const SKEW = 300;

    /** Longest `expires - created` window accepted on a WBA-shape signature. */
    private const MAX_WBA_WINDOW = 86400;

    public function __construct(
        private readonly ProfileFetcher  $profileFetcher,
        private readonly SignatureBase   $signatureBase,
        private readonly JwkVerifier     $jwkVerifier,
        private readonly JwkFormatter    $jwkFormatter,
        private readonly LoggerInterface $logger,
        private readonly AuthorityPolicy $authorityPolicy
    ) {
    }

    public function verify(HttpRequest $request): VerificationResult
    {
        $inputHeader = (string) ($request->getHeader('signature-input') ?: '');
        $sigHeader   = (string) ($request->getHeader('signature') ?: '');

        if (trim($inputHeader) === '' && trim($sigHeader) === '') {
            return VerificationResult::unsigned();
        }

        if (trim($inputHeader) === '' || trim($sigHeader) === '') {
            return VerificationResult::failed(
                'Signature and Signature-Input must both be present.',
                VerificationResult::CODE_SIGNATURE_MISSING
            );
        }

        $inputs     = StructuredFields::parseInnerListDictionary($inputHeader);
        $signatures = StructuredFields::parseByteSequenceDictionary($sigHeader);

        if ($inputs === [] || $signatures === []) {
            return VerificationResult::failed('Signature headers could not be parsed.');
        }

        $rawParams = StructuredFields::rawMembers($inputHeader);
        $skipped   = [];
        $codes     = [];

        foreach ($inputs as $label => $input) {
            if (!isset($signatures[$label], $rawParams[$label])) {
                $skipped[] = $label . ': no matching Signature member';
                $codes[]   = VerificationResult::CODE_SIGNATURE_INVALID;
                continue;
            }

            $outcome = $this->verifyOne(
                $request,
                (string) $label,
                $input,
                $rawParams[$label],
                $signatures[$label]
            );

            if ($outcome instanceof VerificationResult) {
                return $outcome;
            }

            [$reason, $code] = $outcome;
            $skipped[] = $label . ': ' . $reason;
            $codes[]   = $code;
        }

        // Every signature was skipped or rejected. Do not leak which key or
        // which component failed to the caller; log it, return one reason.
        $this->logger->info(
            '[Angeo_Ucp] Inbound signature verification failed. ' . implode(' | ', $skipped)
        );

        $codes = array_values(array_unique($codes));

        return VerificationResult::failed(
            'No signature on this request could be verified.',
            count($codes) === 1 ? $codes[0] : VerificationResult::CODE_SIGNATURE_INVALID
        );
    }

    /**
     * @param array{components: array<int, array{name: string, params: array<string, string>}>, params: array<string, string>} $input
     * @return VerificationResult|array{0: string, 1: string} Result on success, [skip reason, error code] otherwise.
     */
    private function verifyOne(
        HttpRequest $request,
        string $label,
        array $input,
        string $rawParams,
        string $signature
    ): VerificationResult|array {
        $invalid = VerificationResult::CODE_SIGNATURE_INVALID;
        $params  = $input['params'];
        $tag     = $params['tag']   ?? '';
        $keyId   = $params['keyid'] ?? '';
        $alg     = $params['alg']   ?? '';

        if ($keyId === '') {
            return ['missing keyid', $invalid];
        }

        // Signatures scoped to a purpose this verifier does not handle are
        // skipped, not failed.
        if ($tag !== '' && $tag !== 'web-bot-auth') {
            return ['unsupported tag "' . $tag . '"', $invalid];
        }

        if ($alg !== '' && $this->jwkVerifier->normalizeAlgorithm($alg) === null) {
            return ['unsupported alg "' . $alg . '"', VerificationResult::CODE_ALGORITHM_UNSUPPORTED];
        }

        $expiryProblem = $this->checkFreshness($params, $tag === 'web-bot-auth');
        if ($expiryProblem !== null) {
            return [$expiryProblem, $invalid];
        }

        $componentProblem = $this->checkCoveredComponents($request, $input['components']);
        if ($componentProblem !== null) {
            return [$componentProblem, $invalid];
        }

        $digestProblem = $this->checkContentDigest($request);
        if ($digestProblem !== null) {
            return [$digestProblem, VerificationResult::CODE_DIGEST_MISMATCH];
        }

        $authority = $this->signatureBase->requestAuthority($request);
        if (!$this->authorityPolicy->isAllowed($authority)) {
            return ['@authority "' . $authority . '" is not a host of this store', $invalid];
        }

        $profileUrl = $this->resolveProfileUrl($request, $label);
        if ($profileUrl === null) {
            return ['no resolvable UCP-Agent or Signature-Agent profile URL', VerificationResult::CODE_KEY_NOT_FOUND];
        }

        $key = $this->resolveKey($profileUrl, $keyId);
        if ($key === null) {
            return ['no eligible key matching keyid "' . $keyId . '"', VerificationResult::CODE_KEY_NOT_FOUND];
        }

        // WBA-shape signatures bind the advertised key identity to its bytes.
        if ($tag === 'web-bot-auth') {
            try {
                if ($this->jwkFormatter->thumbprint($key) !== $keyId) {
                    return ['keyid is not the RFC 7638 thumbprint of the matched key', $invalid];
                }
            } catch (\Throwable) {
                return ['thumbprint could not be computed for the matched key', $invalid];
            }
        }

        // The algorithm comes from the key (kty/crv).
        $derived = $this->jwkVerifier->algorithmFor($key);
        if ($derived === null || !$this->jwkVerifier->supports($derived)) {
            return ['the matched key\'s type is not supported', VerificationResult::CODE_ALGORITHM_UNSUPPORTED];
        }
        if ($alg !== '' && $this->jwkVerifier->normalizeAlgorithm($alg) !== $derived) {
            return ['alg "' . $alg . '" does not match the key type', $invalid];
        }

        $base = $this->signatureBase->build($request, $input['components'], $rawParams);
        if ($base === null) {
            return ['signature base could not be reconstructed', $invalid];
        }

        if (!$this->jwkVerifier->verify($key, $derived, $base, $signature)) {
            return ['signature did not verify', $invalid];
        }

        return VerificationResult::verified($profileUrl, $keyId);
    }

    /**
     * `created` in the future or `expires` in the past make the signature
     * unusable; a 300-second skew allowance covers ordinary clock drift.
     * Both are integers (RFC 9421 §2.3) when present.
     *
     * WBA-shape signatures MUST carry both (UCP spec, WBA interop), and the
     * window SHOULD be at most 24 hours — enforced here, because a longer
     * window is a longer replay window.
     *
     * @param array<string, string> $params
     */
    private function checkFreshness(array $params, bool $webBotAuth): ?string
    {
        $now = time();

        foreach (['created', 'expires'] as $name) {
            if (isset($params[$name]) && !preg_match('/^\d{1,12}$/', $params[$name])) {
                return '"' . $name . '" is not an integer';
            }
        }

        $created = isset($params['created']) ? (int) $params['created'] : null;
        $expires = isset($params['expires']) ? (int) $params['expires'] : null;

        if ($expires !== null && $expires + self::SKEW < $now) {
            return 'signature expired';
        }

        if ($created !== null && $created - self::SKEW > $now) {
            return 'signature created in the future';
        }

        if ($webBotAuth) {
            if ($created === null || $expires === null) {
                return 'web-bot-auth signature must carry created and expires';
            }
            if ($expires <= $created) {
                return 'expires is not after created';
            }
            if ($expires - $created > self::MAX_WBA_WINDOW) {
                return 'web-bot-auth validity window is longer than 24 hours';
            }
        }

        return null;
    }

    /**
     * @param array<int, array{name: string, params: array<string, string>}> $components
     */
    private function checkCoveredComponents(HttpRequest $request, array $components): ?string
    {
        $covered = array_map(static fn (array $c): string => $c['name'], $components);

        $required = self::REQUIRED_DERIVED;

        if ((string) parse_url((string) $request->getRequestUri(), PHP_URL_QUERY) !== '') {
            $required[] = '@query';
        }

        if (trim((string) $request->getContent()) !== '') {
            foreach (self::REQUIRED_BODY as $name) {
                $required[] = $name;
            }
        }

        foreach (self::REQUIRED_IF_PRESENT as $name) {
            $header = $request->getHeader($name);
            if ($header !== false && $header !== null && trim((string) $header) !== '') {
                $required[] = $name;
            }
        }

        foreach ($required as $name) {
            if (!in_array($name, $covered, true)) {
                return 'signature does not cover "' . $name . '"';
            }
        }

        return null;
    }

    /**
     * A covered Content-Digest is only meaningful if it matches the body.
     */
    private function checkContentDigest(HttpRequest $request): ?string
    {
        $body = (string) $request->getContent();
        if (trim($body) === '') {
            return null;
        }

        $header = (string) ($request->getHeader('content-digest') ?: '');
        if (trim($header) === '') {
            return 'body present but Content-Digest missing';
        }

        $algorithms = ['sha-256' => 'sha256', 'sha-512' => 'sha512'];
        $matchedAny = false;

        foreach ($algorithms as $label => $phpAlgorithm) {
            if (!preg_match('/\b' . preg_quote($label, '/') . '=:([A-Za-z0-9+\/=]+):/i', $header, $m)) {
                continue;
            }

            $matchedAny = true;
            $expected   = base64_encode(hash($phpAlgorithm, $body, true));

            if (!hash_equals($expected, $m[1])) {
                return 'Content-Digest does not match the request body';
            }
        }

        return $matchedAny ? null : 'Content-Digest carries no supported algorithm';
    }

    /**
     * UCP-Agent (its `profile` member) is the default mechanism. Without
     * it, the Signature-Agent member keyed by this signature's label, with
     * `type=jwks_uri`, also resolves to a profile; cimd and directory are
     * not implemented and fall through.
     */
    private function resolveProfileUrl(HttpRequest $request, string $label): ?string
    {
        $profile = AgentHeader::profile((string) ($request->getHeader('ucp-agent') ?: ''));
        if ($profile !== '') {
            return $profile;
        }

        $signatureAgent = trim((string) ($request->getHeader('signature-agent') ?: ''));
        if ($signatureAgent === '') {
            return null;
        }

        $member = StructuredFields::parseStringDictionary($signatureAgent)[$label] ?? null;
        if ($member !== null && ($member['params']['type'] ?? '') === 'jwks_uri') {
            return $member['value'];
        }

        return null;
    }

    /**
     * Fetch the peer profile and pick the key matching `keyid`.
     *
     * On a kid miss the profile is force-refreshed once — a counterparty
     * that just rotated should not be locked out for the whole TTL — and
     * the fetcher rate-limits that to once per origin per TTL floor.
     *
     * @return array<string, mixed>|null
     */
    private function resolveKey(string $profileUrl, string $keyId): ?array
    {
        foreach ([false, true] as $forceRefresh) {
            $profile = $this->profileFetcher->fetch($profileUrl, $forceRefresh);
            if ($profile === null) {
                return null;
            }

            $keys = $this->profileFetcher->verificationKeys($profile);
            if (isset($keys[$keyId])) {
                return $keys[$keyId];
            }
        }

        return null;
    }
}
