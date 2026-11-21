<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Network;

use Angeo\Ucp\Model\Network\HostResolver;
use Angeo\Ucp\Model\Network\PublicAddressGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublicAddressGuard::class)]
class PublicAddressGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function specialUse(): array
    {
        return [
            'this network'          => ['0.0.0.1'],
            'private 10/8'          => ['10.1.2.3'],
            'CGNAT / Alibaba meta'  => ['100.100.100.200'],
            'CGNAT low'             => ['100.64.0.1'],
            'loopback'              => ['127.0.0.1'],
            'cloud metadata'        => ['169.254.169.254'],
            'private 172.16/12'     => ['172.20.0.1'],
            'IETF 192.0.0/24'       => ['192.0.0.1'],
            'TEST-NET-1'            => ['192.0.2.10'],
            'private 192.168/16'    => ['192.168.1.1'],
            'benchmarking'          => ['198.18.0.1'],
            'TEST-NET-3'            => ['203.0.113.5'],
            'multicast'             => ['224.0.0.1'],
            'broadcast'             => ['255.255.255.255'],
            'IPv6 unspecified'      => ['::'],
            'IPv6 loopback'         => ['::1'],
            'IPv4-compatible'       => ['::7f00:1'],
            'mapped loopback'       => ['::ffff:127.0.0.1'],
            'mapped metadata'       => ['::ffff:169.254.169.254'],
            'mapped private'        => ['::ffff:10.0.0.1'],
            'NAT64 of loopback'     => ['64:ff9b::7f00:1'],
            'NAT64 local-use'       => ['64:ff9b:1::1'],
            '6to4 of private'       => ['2002:c0a8:0101::1'],
            'Teredo'                => ['2001:0:4136:e378::1'],
            'documentation v6'      => ['2001:db8::1'],
            'unique local'          => ['fd00::1'],
            'link-local v6'         => ['fe80::1'],
            'multicast v6'          => ['ff02::1'],
            'not an address'        => ['example.com'],
        ];
    }

    #[Test]
    #[DataProvider('specialUse')]
    public function special_use_addresses_are_not_public(string $ip): void
    {
        self::assertFalse($this->guard([])->isPublicIp($ip), $ip);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'Google DNS'          => ['8.8.8.8'],
            'Cloudflare'          => ['1.1.1.1'],
            'just above CGNAT'    => ['100.128.0.1'],
            'just below 172.16'   => ['172.15.255.254'],
            'Google v6'           => ['2001:4860:4860::8888'],
            'mapped public'       => ['::ffff:8.8.8.8'],
            'NAT64 of public'     => ['64:ff9b::808:808'],
        ];
    }

    #[Test]
    #[DataProvider('publicAddresses')]
    public function global_addresses_are_public(string $ip): void
    {
        self::assertTrue($this->guard([])->isPublicIp($ip), $ip);
    }

    #[Test]
    public function a_name_with_one_private_answer_is_refused(): void
    {
        // DNS rebinding setup: one public and one private record.
        $guard = $this->guard(['agent.example' => ['93.184.216.34', '127.0.0.1']]);

        self::assertNull($guard->inspect('https://agent.example/.well-known/ucp', $reason));
        self::assertStringContainsString('127.0.0.1', (string) $reason);
    }

    #[Test]
    public function an_aaaa_record_mapping_to_loopback_is_refused(): void
    {
        $guard = $this->guard(['agent.example' => ['::ffff:127.0.0.1']]);

        self::assertNull($guard->inspect('https://agent.example/p'));
    }

    #[Test]
    public function a_public_name_is_pinned_to_the_checked_address(): void
    {
        $guard  = $this->guard(['Agent.Example' => [], 'agent.example' => ['2606:2800:220:1::1', '93.184.216.34']]);
        $target = $guard->inspect('https://Agent.Example/.well-known/ucp');

        self::assertSame(['host' => 'agent.example', 'port' => 443, 'ip' => '93.184.216.34'], $target);
        self::assertSame('agent.example:443:93.184.216.34', PublicAddressGuard::resolveEntry((array) $target));
    }

    #[Test]
    public function an_ipv6_pin_is_bracketed(): void
    {
        $target = $this->guard(['v6.example' => ['2606:2800:220:1::1']])->inspect('https://v6.example/');

        self::assertSame('v6.example:443:[2606:2800:220:1::1]', PublicAddressGuard::resolveEntry((array) $target));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'http'              => ['http://agent.example/p', 'not https'],
            'other port'        => ['https://agent.example:6379/p', 'port 6379'],
            'userinfo'          => ['https://user:pw@agent.example/p', 'userinfo'],
            'no host'           => ['https:///p', 'host'],
            'literal loopback'  => ['https://127.0.0.1/p', 'special-use'],
            'literal v6 loop'   => ['https://[::1]/p', 'special-use'],
            'unresolvable'      => ['https://nowhere.example/p', 'does not resolve'],
            'file scheme'       => ['file:///etc/passwd', 'host'],
        ];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function refuses_unsafe_urls(string $url, string $why): void
    {
        $guard = $this->guard(['agent.example' => ['93.184.216.34']]);

        self::assertNull($guard->inspect($url, $reason), $url);
        self::assertStringContainsString($why, (string) $reason);
    }

    #[Test]
    public function extra_ports_can_be_allowed_in_di(): void
    {
        $guard = new PublicAddressGuard($this->resolver(['agent.example' => ['93.184.216.34']]), ['443', '8443']);

        self::assertNotNull($guard->inspect('https://agent.example:8443/p'));
        self::assertNull($guard->inspect('https://agent.example:8080/p'));
    }

    /**
     * @param array<string, array<int, string>> $dns
     */
    private function guard(array $dns): PublicAddressGuard
    {
        return new PublicAddressGuard($this->resolver($dns));
    }

    /**
     * @param array<string, array<int, string>> $dns
     */
    private function resolver(array $dns): HostResolver
    {
        $resolver = $this->createMock(HostResolver::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (string $host): array => $dns[$host] ?? []
        );

        return $resolver;
    }
}
