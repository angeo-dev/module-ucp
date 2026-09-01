<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches a counterparty's UCP profile so its `keys[]` can verify an
 * inbound signature.
 *
 * This class dereferences a URL supplied by the REQUEST. That makes it an
 * SSRF primitive unless every rule below holds, which is why the spec's
 * "Fetching" section is normative rather than advisory. Implemented here:
 *
 *  - MUST reject non-HTTPS URLs.
 *  - MUST NOT follow redirects (3xx).
 *  - MUST reject URLs resolving to special-use IP ranges (RFC 6890) —
 *    loopback, link-local including the cloud metadata address
 *    169.254.169.254, private and reserved. The RESOLVED address is
 *    checked, not just the hostname, which is what resists DNS rebinding.
 *  - SHOULD bound the response body; the spec says not below 128 KiB, so
 *    that is the floor used here.
 *  - SHOULD enforce connect and response timeouts.
 *  - SHOULD cache with a TTL floor of 60 seconds regardless of the
 *    origin's Cache-Control.
 *  - On an unknown `kid`, SHOULD force-refresh once, but MUST NOT do so
 *    more than once per TTL floor per origin.
 */
class ProfileFetcher
{
    private const CACHE_TAG        = 'ANGEO_UCP_PEER_PROFILE';
    private const TTL_FLOOR        = 60;
    private const MAX_BODY_BYTES   = 131072; // 128 KiB
    private const CONNECT_TIMEOUT  = 3;
    private const TOTAL_TIMEOUT    = 5;

    public function __construct(
        private readonly CacheInterface  $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param bool $forceRefresh Set only after a kid miss, and rate-limited
     *        by the caller's own guard.
     * @return array<string, mixed>|null Decoded profile, or null on any failure.
     */
    public function fetch(string $url, bool $forceRefresh = false): ?array
    {
        if (!$this->isFetchable($url)) {
            return null;
        }

        $cacheKey = self::CACHE_TAG . '_' . hash('sha256', $url);

        if (!$forceRefresh) {
            $cached = $this->cache->load($cacheKey);
            if (is_string($cached) && $cached !== '') {
                try {
                    $decoded = json_decode($cached, true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                } catch (\JsonException) {
                    // Fall through and refetch.
                }
            }
        } elseif (!$this->allowForcedRefresh($url)) {
            // MUST NOT force-refresh more than once per TTL floor per origin.
            return null;
        }

        $body = $this->request($url);
        if ($body === null) {
            return null;
        }

        try {
            $profile = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                '[Angeo_Ucp] Peer profile at ' . $url . ' is not valid JSON: ' . $e->getMessage()
            );
            return null;
        }

        if (!is_array($profile)) {
            return null;
        }

        $this->cache->save($body, $cacheKey, [self::CACHE_TAG], self::TTL_FLOOR);

        return $profile;
    }

    /**
     * Public keys from a fetched profile, restricted to those usable for
     * signature verification.
     *
     * RFC 7517 §4.2/§4.3: skip any key marked `use: "enc"`, or whose
     * `key_ops` is present and does not include "verify". Keys that set
     * `use: "sig"` or omit both members remain eligible.
     *
     * @param array<string, mixed> $profile
     * @return array<string, array<string, mixed>> kid => JWK
     */
    public function verificationKeys(array $profile): array
    {
        $keys = $profile['keys'] ?? null;
        if (!is_array($keys)) {
            return [];
        }

        $usable = [];

        foreach ($keys as $key) {
            if (!is_array($key) || empty($key['kid']) || !is_string($key['kid'])) {
                continue;
            }

            if (($key['use'] ?? null) === 'enc') {
                continue;
            }

            $keyOps = $key['key_ops'] ?? null;
            if (is_array($keyOps) && !in_array('verify', $keyOps, true)) {
                continue;
            }

            $usable[$key['kid']] = $key;
        }

        return $usable;
    }

    /**
     * HTTPS, parseable, no userinfo, and not pointed at internal space.
     */
    private function isFetchable(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $this->resolvesToPublicAddress((string) $parts['host']);
    }

    /**
     * Resolve the hostname and reject any special-use address.
     *
     * Checking the resolved address rather than the literal hostname is the
     * point: `internal.example.com` can resolve to 127.0.0.1, and the
     * cloud metadata endpoint is reachable by name on several providers.
     */
    private function resolvesToPublicAddress(string $host): bool
    {
        $addresses = [];

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses[] = $host;
        } else {
            foreach (['A' => DNS_A, 'AAAA' => DNS_AAAA] as $type => $flag) {
                $records = @dns_get_record($host, $flag) ?: [];
                foreach ($records as $record) {
                    $addresses[] = $record[$type === 'A' ? 'ip' : 'ipv6'] ?? '';
                }
            }
        }

        $addresses = array_values(array_filter($addresses));

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            $public = filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );

            // Every resolved address must be public. One private answer is
            // enough to make the name untrustworthy.
            if ($public === false) {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] Refusing to fetch peer profile: host "%s" resolves '
                    . 'to the special-use address %s.',
                    $host,
                    $address
                ));
                return false;
            }
        }

        return true;
    }

    private function request(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            $this->logger->error(
                '[Angeo_Ucp] ext-curl is required to verify inbound UCP signatures.'
            );
            return null;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            // MUST NOT follow redirects.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'Angeo-UCP/2.1 (+https://angeo.dev)',
            // Abort as soon as the body exceeds the bound, instead of
            // buffering an unbounded response.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($h, $expected, $downloaded): int
                => $downloaded > self::MAX_BODY_BYTES ? 1 : 0,
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        curl_close($handle);

        if ($body === false || $status !== 200) {
            $this->logger->info(sprintf(
                '[Angeo_Ucp] Peer profile fetch failed for %s (HTTP %d)%s',
                $url,
                $status,
                $error !== '' ? ': ' . $error : ''
            ));
            return null;
        }

        return (string) $body;
    }

    /**
     * One forced refresh per origin per TTL floor.
     */
    private function allowForcedRefresh(string $url): bool
    {
        $origin = (string) parse_url($url, PHP_URL_HOST);
        $key    = self::CACHE_TAG . '_REFRESH_' . hash('sha256', $origin);

        if ($this->cache->load($key) !== false) {
            return false;
        }

        $this->cache->save('1', $key, [self::CACHE_TAG], self::TTL_FLOOR);

        return true;
    }
}
