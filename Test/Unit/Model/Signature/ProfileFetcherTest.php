<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Network\HostResolver;
use Angeo\Ucp\Model\Network\PublicAddressGuard;
use Angeo\Ucp\Model\Signature\ProfileFetcher;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ProfileFetcher::class)]
class ProfileFetcherTest extends TestCase
{
    #[Test]
    public function refuses_internal_targets_without_touching_cache_or_network(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::never())->method('load');
        $cache->expects(self::never())->method('save');

        $fetcher = new ProfileFetcher($cache, $this->createMock(LoggerInterface::class), $this->guard([
            'meta.example' => ['100.100.100.200'],
        ]));

        self::assertNull($fetcher->fetch('https://meta.example/latest/meta-data'));
        self::assertNull($fetcher->fetch('https://127.0.0.1/.well-known/ucp'));
        self::assertNull($fetcher->fetch('http://agent.example/.well-known/ucp'));
    }

    #[Test]
    public function serves_a_cached_profile_after_the_address_check(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('{"keys":[{"kid":"k1","kty":"EC"}]}');
        $cache->expects(self::never())->method('save');

        $fetcher = new ProfileFetcher($cache, $this->createMock(LoggerInterface::class), $this->guard([
            'agent.example' => ['93.184.216.34'],
        ]));

        self::assertSame(['keys' => [['kid' => 'k1', 'kty' => 'EC']]], $fetcher->fetch('https://agent.example/p'));
    }

    #[Test]
    public function forced_refresh_is_rate_limited_per_origin(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        // The refresh marker is already set for this origin.
        $cache->method('load')->willReturn('1');

        $fetcher = new ProfileFetcher($cache, $this->createMock(LoggerInterface::class), $this->guard([
            'agent.example' => ['93.184.216.34'],
        ]));

        self::assertNull($fetcher->fetch('https://agent.example/p', true));
    }

    #[Test]
    public function curl_is_pinned_and_hardened(): void
    {
        $fetcher = new ProfileFetcher(
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->guard([])
        );

        $options = $fetcher->curlOptions(['host' => 'agent.example', 'port' => 443, 'ip' => '93.184.216.34']);

        self::assertSame(['agent.example:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertLessThanOrEqual(5, $options[CURLOPT_TIMEOUT]);
        self::assertSame(1, ($options[CURLOPT_PROGRESSFUNCTION])(null, 0, 131073));
        self::assertSame(0, ($options[CURLOPT_PROGRESSFUNCTION])(null, 0, 1024));
    }

    #[Test]
    public function verification_keys_skip_encryption_keys(): void
    {
        $fetcher = new ProfileFetcher(
            $this->createMock(CacheInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->guard([])
        );

        $keys = $fetcher->verificationKeys(['keys' => [
            ['kid' => 'sig', 'use' => 'sig'],
            ['kid' => 'enc', 'use' => 'enc'],
            ['kid' => 'ops-sign-only', 'key_ops' => ['sign']],
            ['kid' => 'ops-verify', 'key_ops' => ['verify']],
            ['kid' => 'plain'],
            ['no-kid' => true],
        ]]);

        self::assertSame(['sig', 'ops-verify', 'plain'], array_keys($keys));
    }

    /**
     * @param array<string, array<int, string>> $dns
     */
    private function guard(array $dns): PublicAddressGuard
    {
        $resolver = $this->createMock(HostResolver::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $h): array => $dns[$h] ?? []);

        return new PublicAddressGuard($resolver);
    }
}
