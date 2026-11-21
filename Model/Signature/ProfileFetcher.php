<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\Network\PublicAddressGuard;
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
 *    169.254.169.254, private, shared (100.64.0.0/10) and reserved. Every
 *    RESOLVED address is checked by PublicAddressGuard, and curl connects
 *    to the checked address (CURLOPT_RESOLVE), so a second DNS answer
 *    cannot redirect the request (DNS rebinding). 2.2.2: before, curl
 *    resolved the name again on its own.
 *  - Only port 443 by default (PublicAddressGuard `allowedPorts`).
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
        private readonly CacheInterface     $cache,
        private readonly LoggerInterface    $logger,
        private readonly PublicAddressGuard $addressGuard
    ) {
    }

    /**
     * @param bool $forceRefresh Set only after a kid miss, and rate-limited
     *        by the caller's own guard.
     * @return array<string, mixed>|null Decoded profile, or null on any failure.
     */
    public function fetch(string $url, bool $forceRefresh = false): ?array
    {
        $target = $this->addressGuard->inspect($url, $reason);
        if ($target === null) {
            $this->logger->info(sprintf(
                '[Angeo_Ucp] Refusing to fetch peer profile %s: %s.',
                $url,
                (string) $reason
            ));
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

        $body = $this->request($url, $target);
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
     * The curl options for one fetch. Public so the hardening can be
     * asserted in tests: no redirects, https only, pinned address, bounded
     * body and time.
     *
     * @param array{host: string, port: int, ip: string} $target From PublicAddressGuard::inspect().
     * @return array<int, mixed>
     */
    public function curlOptions(array $target): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            // MUST NOT follow redirects.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            // Connect to the address that was checked, not to a fresh
            // DNS answer.
            CURLOPT_RESOLVE        => [PublicAddressGuard::resolveEntry($target)],
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'Angeo-UCP/' . Config::MODULE_VERSION . ' (+https://angeo.dev)',
            // Abort as soon as the body exceeds the bound, instead of
            // buffering an unbounded response.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($h, $expected, $downloaded): int
                => $downloaded > self::MAX_BODY_BYTES ? 1 : 0,
        ];
    }

    /**
     * @param array{host: string, port: int, ip: string} $target
     */
    private function request(string $url, array $target): ?string
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

        curl_setopt_array($handle, $this->curlOptions($target));

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($handle);
        // No curl_close(): it has no effect since PHP 8.0 and is deprecated
        // in 8.5. The handle is freed when it goes out of scope.
        unset($handle);

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
