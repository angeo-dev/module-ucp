<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 2.2.0: capabilities that need endpoints are advertised only when a module
 * registers an implementation, whatever the admin toggle says.
 */
#[CoversClass(Config::class)]
class CapabilityGatingTest extends TestCase
{
    private function config(array $implementations): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturn('');

        return new Config(
            $scopeConfig,
            $this->createStub(StoreManagerInterface::class),
            new NullLogger(),
            $implementations
        );
    }

    #[Test]
    public function toggles_without_implementation_are_not_declared(): void
    {
        $config = $this->config([]);

        self::assertFalse($config->isCheckoutDeclared());
        self::assertFalse($config->isCartDeclared());
        self::assertFalse($config->isOrderDeclared());
        self::assertFalse($config->isFulfillmentDeclared());
        self::assertFalse($config->isDiscountDeclared());
        self::assertFalse($config->isBuyerConsentDeclared());
        self::assertSame(Config::GATED_CAPABILITIES, $config->getUnimplementedToggles());
    }

    #[Test]
    public function registered_implementation_is_declared(): void
    {
        $config = $this->config([
            'dev.ucp.shopping.checkout' => true,
            'dev.ucp.shopping.discount' => true,
        ]);

        self::assertTrue($config->isCheckoutDeclared());
        self::assertTrue($config->isDiscountDeclared());
        self::assertFalse($config->isOrderDeclared());
        self::assertNotContains('dev.ucp.shopping.checkout', $config->getUnimplementedToggles());
    }

    #[Test]
    public function catalog_and_permalink_are_never_gated(): void
    {
        $config = $this->config([]);

        self::assertTrue($config->isCatalogSearchDeclared());
        self::assertTrue($config->isCatalogLookupDeclared());
        self::assertTrue($config->isPermalinkDeclared());
        self::assertTrue($config->isImplemented('dev.ucp.shopping.catalog.search'));
    }
}
