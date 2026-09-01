<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Angeo\Ucp\Api\SignatureVerifierInterface;
use Angeo\Ucp\Model\AuthorityBinding;
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
 * Key resolution uses the default UCP mechanism: read the `UCP-Agent`
 * header, fetch that profile, and match `keyid` against its `keys[]`.
 * `Signature-Agent` (Web Bot Auth) is an optional additive layer; this
 * implementation resolves the `jwks_uri` form, which points back at a
 * profile, and skips `cimd` and `directory` rather than pretending to
 * support them.
 *
 * Covered-component enforcement is where most of the security lives: a
 * signature that does not cover the request target, the body digest, or a
 * present `ucp-agent` / `signature-agent` / `idempotency-key` header is
 * skipped, because an uncovered component is an unsigned component.
 */
class SignatureVerifier implements SignatureVerifierInterface
{
    /** Always required. @query is added when a query string is present. */
    private const REQUIRED_DERIVED = ['@method', '@authority', '@path'];

    /** Required when the request carries a body. */
    private const REQUIRED_BODY = ['content-digest', 'content-type'];

    /** Required when present on the request (closed set per the spec). */
    private const REQUIRED_IF_PRESENT = ['ucp-agent', 'signature-agent', 'idempotency-key'];

    public function __construct(
        private readonly ProfileFetcher  $profileFetcher,
        private readonly SignatureBase   $signatureBase,
        private readonly JwkVerifier     $jwkVerifier,
        private readonly JwkFormatter    $jwkFormatter,
        private readonly LoggerInterface $logger
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
                'Signature and Signature-Input must both be present.'
            );
        }

        $inputs     = StructuredFields::parseInnerListDictionary($inputHeader);
        $signatures = StructuredFields::parseByteSequenceDictionary($sigHeader);

        if ($inputs === [] || $signatures === []) {
            return VerificationResult::failed('Signature headers could not be parsed.');
        }

        $rawParams = $this->rawSignatureParams($inputHeader);
        $skipped   = [];

        foreach ($inputs as $label => $input) {
            if (!isset($signatures[$label], $rawParams[$label])) {
                $skipped[] = $label . ': no matching Signature member';
                continue;
            }

            $outcome = $this->verifyOne(
                $request,
                $input,
                $rawParams[$label],
                $signatures[$label]
            );

            if ($outcome instanceof VerificationResult) {
                return $outcome;
            }

            $skipped[] = $label . ': ' . $outcome;
        }

        // Every signature was skipped or rejected. Do not leak which key or
        // which component failed to the caller; log it, return one reason.
        $this->logger->info(
            '[Angeo_Ucp] Inbound signature verification failed. ' . implode(' | ', $skipped)
        );

        return VerificationResult::failed('No signature on this request could be verified.');
    }

    /**
     * @param array{components: array<int, array{name: string, params: array<string, string>}>, params: array<string, string>} $input
     * @return VerificationResult|string Result on success, skip reason otherwise.
     */
    private function verifyOne(
        HttpRequest $request,
        array $input,
        string $rawParams,
        string $signature
    ): VerificationResult|string {
        $params = $input['params'];
        $tag    = $params['tag']    ?? '';
        $keyId  = $params['keyid']  ?? '';
        $alg    = $params['alg']    ?? '';

        if ($keyId === '' || $alg === '') {
            return 'missing keyid or alg';
        }

        // Signatures scoped to a purpose this verifier does not handle are
        // skipped, not failed.
        if ($tag !== '' && $tag !== 'web-bot-auth') {
            return 'unsupported tag "' . $tag . '"';
        }

        $expiryProblem = $this->checkFreshness($params);
        if ($expiryProblem !== null) {
            return $expiryProblem;
        }

        $componentProblem = $this->checkCoveredComponents($request, $input['components']);
        if ($componentProblem !== null) {
            return $componentProblem;
        }

        $digestProblem = $this->checkContentDigest($request);
        if ($digestProblem !== null) {
            return $digestProblem;
        }

        $profileUrl = $this->resolveProfileUrl($request);
        if ($profileUrl === null) {
            return 'no resolvable UCP-Agent or Signature-Agent profile URL';
        }

        $key = $this->resolveKey($profileUrl, $keyId);
        if ($key === null) {
            return 'no eligible key matching keyid "' . $keyId . '"';
        }

        // WBA-shape signatures bind the advertised key identity to its bytes.
        if ($tag === 'web-bot-auth') {
            try {
                if ($this->jwkFormatter->thumbprint($key) !== $keyId) {
                    return 'keyid is not the RFC 7638 thumbprint of the matched key';
                }
            } catch (\Throwable) {
                return 'thumbprint could not be computed for the matched key';
            }
        }

        $base = $this->signatureBase->build($request, $input['components'], $rawParams);
        if ($base === null) {
            return 'signature base could not be reconstructed';
        }

        if (!$this->jwkVerifier->verify($key, $alg, $base, $signature)) {
            return 'signature did not verify';
        }

        return VerificationResult::verified($profileUrl, $keyId);
    }

    /**
     * `created` in the future or `expires` in the past make the signature
     * unusable. A 300-second skew allowance covers ordinary clock drift.
     *
     * @param array<string, string> $params
     */
    private function checkFreshness(array $params): ?string
    {
        $skew = 300;
        $now  = time();

        if (isset($params['expires']) && is_numeric($params['expires'])
            && (int) $params['expires'] + $skew < $now
        ) {
            return 'signature expired';
        }

        if (isset($params['created']) && is_numeric($params['created'])
            && (int) $params['created'] - $skew > $now
        ) {
            return 'signature created in the future';
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
     * UCP-Agent is the default mechanism. Signature-Agent with
     * `type=jwks_uri` also resolves to a profile; cimd and directory are
     * not implemented and fall through.
     */
    private function resolveProfileUrl(HttpRequest $request): ?string
    {
        $ucpAgent = trim((string) ($request->getHeader('ucp-agent') ?: ''));
        if ($ucpAgent !== '') {
            return trim($ucpAgent, '"');
        }

        $signatureAgent = trim((string) ($request->getHeader('signature-agent') ?: ''));
        if ($signatureAgent === '') {
            return null;
        }

        foreach (StructuredFields::parseStringDictionary($signatureAgent) as $member) {
            if (($member['params']['type'] ?? '') === 'jwks_uri') {
                return $member['value'];
            }
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

    /**
     * Extract each signature's parameter text EXACTLY as it appeared.
     *
     * RFC 9421 requires the `@signature-params` line to echo the original
     * bytes. Re-serialising from the parsed structure would reorder or
     * requote parameters and produce a base that never matches.
     *
     * @return array<string, string>
     */
    private function rawSignatureParams(string $header): array
    {
        $out = [];

        if (!preg_match_all('/([A-Za-z0-9_\-]+)=(\([^)]*\)[^,]*)/', $header, $matches, PREG_SET_ORDER)) {
            return $out;
        }

        foreach ($matches as $match) {
            $out[$match[1]] = trim($match[2]);
        }

        return $out;
    }
}
