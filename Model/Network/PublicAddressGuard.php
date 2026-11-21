<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Network;

/**
 * Decides whether an outbound URL may be fetched, and pins the address.
 *
 * Every outbound call the UCP suite makes goes to a URL that a request
 * supplied: the agent profile from `UCP-Agent`, the webhook URL from that
 * profile. That makes each call an SSRF primitive unless the rules below
 * hold (UCP spec "Fetching", RFC 6890):
 *
 *  - https only, no userinfo, only an allowed port (443 by default);
 *  - the host is RESOLVED and EVERY address must be global — one private
 *    answer makes the name untrustworthy;
 *  - the caller connects to the address checked here, through
 *    CURLOPT_RESOLVE (see resolveEntry), so DNS cannot answer differently
 *    between the check and the connect (DNS rebinding).
 *
 * Before 2.2.2 the profile fetcher checked the name but let curl resolve it
 * again, and its range filter (FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE)
 * missed 100.64.0.0/10 — where Alibaba Cloud serves instance metadata — and
 * IPv4-mapped IPv6 answers such as ::ffff:127.0.0.1.
 */
class PublicAddressGuard
{
    /** @var array<int, int> */
    private readonly array $allowedPorts;

    /**
     * IPv4 special-purpose blocks (IANA registry, RFC 6890 and successors).
     */
    private const BLOCKED_V4 = [
        '0.0.0.0/8',        // "this network"
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // shared address space (CGNAT); Alibaba metadata 100.100.100.200
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local; AWS/GCP/Azure metadata 169.254.169.254
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1
        '192.31.196.0/24',  // AS112-v4
        '192.52.193.0/24',  // AMT
        '192.88.99.0/24',   // 6to4 relay anycast (deprecated)
        '192.168.0.0/16',   // private
        '192.175.48.0/24',  // direct delegation AS112
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, includes 255.255.255.255
    ];

    /**
     * IPv6 special-purpose blocks. Prefixes that EMBED an IPv4 address are
     * handled separately (see EMBEDDING_V6) and are not listed here.
     */
    private const BLOCKED_V6 = [
        '::/96',            // unspecified, loopback, IPv4-compatible (deprecated)
        '64:ff9b:1::/48',   // local-use IPv4/IPv6 translation
        '100::/64',         // discard-only
        '2001::/23',        // IETF protocol assignments (Teredo, ORCHID, …)
        '2001:db8::/32',    // documentation
        '3fff::/20',        // documentation
        '5f00::/16',        // SRv6 SIDs
        'fc00::/7',         // unique local
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local (deprecated)
        'ff00::/8',         // multicast
    ];

    /**
     * IPv6 prefixes that carry an IPv4 address: prefix => byte offset of
     * the embedded address. The embedded address decides.
     */
    private const EMBEDDING_V6 = [
        '::ffff:0:0/96' => 12, // IPv4-mapped
        '64:ff9b::/96'  => 12, // NAT64 well-known prefix
        '2002::/16'     => 2,  // 6to4
    ];

    /**
     * @param array<int|string, int|string> $allowedPorts Ports outbound calls may use. 443 by default.
     */
    public function __construct(
        private readonly HostResolver $resolver,
        array $allowedPorts = [443]
    ) {
        $ports = [];
        foreach ($allowedPorts as $port) {
            $port = (int) $port;
            if ($port > 0 && $port < 65536) {
                $ports[] = $port;
            }
        }
        $this->allowedPorts = $ports !== [] ? array_values(array_unique($ports)) : [443];
    }

    /**
     * Check a URL and pin it to one checked address.
     *
     * @param string|null $reason Set to why the URL was refused.
     * @return array{host: string, port: int, ip: string}|null Null when the URL must not be fetched.
     */
    public function inspect(string $url, ?string &$reason = null): ?array
    {
        $reason = null;
        $parts  = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            $reason = 'not a URL with a host';
            return null;
        }
        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            $reason = 'not https';
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $reason = 'userinfo is not allowed';
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if (!in_array($port, $this->allowedPorts, true)) {
            $reason = 'port ' . $port . ' is not allowed';
            return null;
        }

        $host = strtolower(trim((string) $parts['host'], '[]'));
        if ($host === '') {
            $reason = 'empty host';
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : $this->resolver->resolve($host);

        if ($addresses === []) {
            $reason = 'host does not resolve';
            return null;
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicIp($address)) {
                $reason = 'host resolves to the special-use address ' . $address;
                return null;
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $this->preferredAddress($addresses)];
    }

    /**
     * The CURLOPT_RESOLVE entry that pins a checked target: "host:port:ip".
     *
     * @param array{host: string, port: int, ip: string} $target
     */
    public static function resolveEntry(array $target): string
    {
        $ip = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];

        return $target['host'] . ':' . $target['port'] . ':' . $ip;
    }

    /**
     * Whether an address is globally routable — not loopback, private,
     * link-local, shared, reserved, documentation or multicast.
     */
    public function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->isPublicV4($packed, $ip);
        }

        foreach (self::EMBEDDING_V6 as $cidr => $offset) {
            if (self::inCidr($packed, $cidr)) {
                $embedded = substr($packed, $offset, 4);
                return $this->isPublicV4($embedded, (string) inet_ntop($embedded));
            }
        }

        foreach (self::BLOCKED_V6 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return self::passesGlobalFilter($ip);
    }

    private function isPublicV4(string $packed, string $ip): bool
    {
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return false;
            }
        }

        return self::passesGlobalFilter($ip);
    }

    /**
     * PHP 8.2+ ships the IANA global-range check; use it as a second
     * opinion. PHP 8.1 relies on the lists above alone.
     */
    private static function passesGlobalFilter(string $ip): bool
    {
        if (!defined('FILTER_FLAG_GLOBAL_RANGE')) {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, (int) constant('FILTER_FLAG_GLOBAL_RANGE')) !== false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = inet_pton($network);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits  = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }

        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * IPv4 first: more hosts reach it reliably.
     *
     * @param array<int, string> $addresses
     */
    private function preferredAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (!str_contains($address, ':')) {
                return $address;
            }
        }

        return $addresses[0];
    }
}
