<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    #[Test]
    public function getPublicSigningKeys_strips_private_key_fields(): void
    {
        $contaminatedJwk = [[
            'kid' => 'leak-test',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'public-x',
            'y' => 'public-y',
            'd' => 'PRIVATE-MUST-NOT-LEAK',
            'use' => 'sig',
            'alg' => 'ES256',
        ]];
        $config = $this->buildConfig(jwkJson: json_encode($contaminatedJwk));

        $keys = $config->getPublicSigningKeys();

        self::assertCount(1, $keys);
        self::assertArrayNotHasKey('d', $keys[0]);
        self::assertArrayHasKey('x', $keys[0]);
        self::assertArrayHasKey('y', $keys[0]);
        self::assertSame('public-x', $keys[0]['x']);
    }

    #[Test]
    public function getPublicSigningKeys_logs_warning_when_private_fields_stripped(): void
    {
        $contaminatedJwk = [[
            'kid' => 'leak-test',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'public-x',
            'y' => 'public-y',
            'd' => 'PRIVATE',
        ]];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('private fields'));

        $config = $this->buildConfig(
            jwkJson: json_encode($contaminatedJwk),
            logger: $logger
        );

        $config->getPublicSigningKeys();
    }

    #[Test]
    public function getPublicSigningKeys_returns_empty_array_for_blank_config(): void
    {
        $config = $this->buildConfig(jwkJson: '');

        self::assertSame([], $config->getPublicSigningKeys());
    }

    #[Test]
    public function getPublicSigningKeys_returns_empty_array_for_invalid_json(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $config = $this->buildConfig(jwkJson: '{not valid json', logger: $logger);

        self::assertSame([], $config->getPublicSigningKeys());
    }

    #[Test]
    public function getRestEndpoint_uses_store_base_url_when_blank(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('https://shop.example.com/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $config = $this->buildConfig(endpoint: '', storeManager: $storeManager);

        self::assertSame('https://shop.example.com/rest/V1/ucp', $config->getRestEndpoint());
    }

    #[Test]
    public function getRestEndpoint_uses_configured_value_when_present(): void
    {
        $config = $this->buildConfig(endpoint: 'https://ucp.example.com/api/v2/');

        self::assertSame('https://ucp.example.com/api/v2', $config->getRestEndpoint());
    }

    #[Test]
    public function getRestEndpoint_returns_empty_and_logs_when_store_resolution_throws(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')
            ->willThrowException(new \RuntimeException('no store'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('store base URL'));

        $config = $this->buildConfig(
            endpoint: '',
            storeManager: $storeManager,
            logger: $logger
        );

        self::assertSame('', $config->getRestEndpoint());
    }

    private function buildConfig(
        string $jwkJson = '',
        string $endpoint = '',
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null
    ): Config {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($jwkJson, $endpoint): string {
                return match ($path) {
                    Config::XML_PATH_SIGNING_KEY_JWK => $jwkJson,
                    Config::XML_PATH_REST_ENDPOINT => $endpoint,
                    default => '',
                };
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $storeManager ??= $this->createStub(StoreManagerInterface::class);
        $logger ??= new NullLogger();

        return new Config($scopeConfig, $storeManager, $logger);
    }
}
