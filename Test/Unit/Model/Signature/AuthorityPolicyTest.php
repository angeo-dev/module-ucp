<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\Signature\AuthorityPolicy;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(AuthorityPolicy::class)]
class AuthorityPolicyTest extends TestCase
{
    #[Test]
    public function allows_only_store_hosts(): void
    {
        $policy = $this->policy(true, [
            ['https://shop.example/', 'http://shop.example/'],
            ['https://de.shop.example:8443/', 'http://de.shop.example/'],
        ]);

        self::assertTrue($policy->isAllowed('shop.example'));
        self::assertTrue($policy->isAllowed('SHOP.example'));
        self::assertTrue($policy->isAllowed('de.shop.example:8443'));
        self::assertFalse($policy->isAllowed('de.shop.example:9443'));
        self::assertFalse($policy->isAllowed('other-shop.example'));
    }

    #[Test]
    public function the_check_can_be_switched_off(): void
    {
        self::assertTrue($this->policy(false, [['https://shop.example/', 'http://shop.example/']])->isAllowed('x.example'));
    }

    #[Test]
    public function authority_form_drops_default_ports(): void
    {
        self::assertSame('shop.example', AuthorityPolicy::authorityOf('https://Shop.Example:443/'));
        self::assertSame('shop.example', AuthorityPolicy::authorityOf('http://shop.example:80/x'));
        self::assertSame('shop.example:8080', AuthorityPolicy::authorityOf('https://shop.example:8080/'));
        self::assertSame('', AuthorityPolicy::authorityOf('not a url'));
    }

    /**
     * @param array<int, array{0: string, 1: string}> $baseUrls [secure, unsecure] per store
     */
    private function policy(bool $enabled, array $baseUrls): AuthorityPolicy
    {
        $stores = [];
        foreach ($baseUrls as [$secure, $unsecure]) {
            $store = $this->createMock(Store::class);
            $store->method('getBaseUrl')->willReturnCallback(
                static fn (string $type = 'link', ?bool $isSecure = null): string => $isSecure ? $secure : $unsecure
            );
            $stores[] = $store;
        }

        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStores')->willReturn($stores);

        $config = $this->createMock(Config::class);
        $config->method('isAuthorityCheckEnabled')->willReturn($enabled);

        return new AuthorityPolicy($manager, $config, $this->createMock(LoggerInterface::class));
    }
}
