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
    // ── getPublicSigningKeys ──────────────────────────────────────────────────

    #[Test]
    public function getPublicSigningKeys_strips_private_key_fields(): void
    {
        $contaminatedJwk = [[
            'kid' => 'leak-test',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => 'cHVibGljLXg',
            'y'   => 'cHVibGljLXk',
            'd'   => 'PRIVATE-MUST-NOT-LEAK',
            'use' => 'sig',
            'alg' => 'ES256',
        ]];
        $config = $this->buildConfig(jwkJson: json_encode($contaminatedJwk));

        $keys = $config->getPublicSigningKeys();

        self::assertCount(1, $keys);
        self::assertArrayNotHasKey('d', $keys[0]);
        self::assertArrayHasKey('x', $keys[0]);
        self::assertArrayHasKey('y', $keys[0]);
        self::assertSame('cHVibGljLXg', $keys[0]['x']);
    }

    #[Test]
    public function getPublicSigningKeys_logs_warning_when_private_fields_stripped(): void
    {
        $contaminatedJwk = [[
            'kid' => 'leak-test',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => 'cHVibGljLXg',
            'y'   => 'cHVibGljLXk',
            'd'   => 'PRIVATE',
        ]];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
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
    public function getPublicSigningKeys_skips_entries_missing_required_fields(): void
    {
        // Entry missing 'y' coordinate — should be rejected.
        $incomplete = [[
            'kid' => 'bad-entry',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => 'cHVibGljLXg',
            // 'y' intentionally absent
        ]];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('missing required fields'));

        $config = $this->buildConfig(jwkJson: json_encode($incomplete), logger: $logger);

        self::assertSame([], $config->getPublicSigningKeys());
    }

    // ── getRestEndpoint ───────────────────────────────────────────────────────

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

    #[Test]
    public function getRestEndpoint_logs_warning_for_non_https_configured_url(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('not HTTPS'));

        $config = $this->buildConfig(
            endpoint: 'http://insecure.example.com/rest/V1/ucp',
            logger: $logger
        );

        self::assertSame(
            'http://insecure.example.com/rest/V1/ucp',
            $config->getRestEndpoint()
        );
    }

    #[Test]
    public function getRestEndpoint_logs_warning_for_non_https_derived_url(): void
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getBaseUrl')->willReturn('http://insecure.example.com/');

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('not HTTPS'));

        $config = $this->buildConfig(
            endpoint: '',
            storeManager: $storeManager,
            logger: $logger
        );

        $config->getRestEndpoint();
    }


    // ── getIdentityLinkingScopes ──────────────────────────────────────────────

    #[Test]
    public function getIdentityLinkingScopes_parses_comma_separated_list(): void
    {
        $config = $this->buildConfig(
            identityScopes: ' dev.ucp.shopping.order:read, dev.ucp.shopping.order:manage ,, dev.ucp.shopping.order:read '
        );

        self::assertSame(
            ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.order:manage'],
            $config->getIdentityLinkingScopes()
        );
    }

    #[Test]
    public function getIdentityLinkingScopes_returns_empty_for_blank(): void
    {
        $config = $this->buildConfig(identityScopes: '   ');

        self::assertSame([], $config->getIdentityLinkingScopes());
    }

    // ── getSupportedVersions ──────────────────────────────────────────────────

    #[Test]
    public function getSupportedVersions_accepts_valid_map(): void
    {
        $config = $this->buildConfig(
            supportedVersions: '{"2026-01-23": "https://shop.example.com/ucp/profiles/2026-01-23.json"}'
        );

        self::assertSame(
            ['2026-01-23' => 'https://shop.example.com/ucp/profiles/2026-01-23.json'],
            $config->getSupportedVersions()
        );
    }

    #[Test]
    public function getSupportedVersions_rejects_non_date_keys_and_non_https_uris(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');

        $config = $this->buildConfig(
            supportedVersions: '{"v1": "https://a.example/p.json", "2026-01-23": "http://insecure.example/p.json"}',
            logger: $logger
        );

        self::assertSame([], $config->getSupportedVersions());
    }

    #[Test]
    public function getSupportedVersions_ignores_invalid_json_with_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $config = $this->buildConfig(supportedVersions: '{broken', logger: $logger);

        self::assertSame([], $config->getSupportedVersions());
    }

    // ── getPaymentHandlers ────────────────────────────────────────────────────

    #[Test]
    public function getPaymentHandlers_accepts_json_object(): void
    {
        $json = '{"com.example.tokenizer": [{"id": "t1", "version": "2026-04-08"}]}';
        $config = $this->buildConfig(paymentHandlers: $json);

        $handlers = $config->getPaymentHandlers();

        self::assertArrayHasKey('com.example.tokenizer', $handlers);
    }

    #[Test]
    public function getPaymentHandlers_rejects_json_array_with_warning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('payment_handlers'));

        $config = $this->buildConfig(paymentHandlers: '[1,2,3]', logger: $logger);

        self::assertSame([], $config->getPaymentHandlers());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildConfig(
        string $jwkJson = '',
        string $endpoint = '',
        string $identityScopes = '',
        string $supportedVersions = '',
        string $paymentHandlers = '',
        ?StoreManagerInterface $storeManager = null,
        ?LoggerInterface $logger = null
    ): Config {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use (
                $jwkJson,
                $endpoint,
                $identityScopes,
                $supportedVersions,
                $paymentHandlers
            ): string {
                return match ($path) {
                    Config::XML_PATH_SIGNING_KEY_JWK     => $jwkJson,
                    Config::XML_PATH_REST_ENDPOINT       => $endpoint,
                    Config::XML_PATH_IDENTITY_SCOPES     => $identityScopes,
                    Config::XML_PATH_SUPPORTED_VERSIONS  => $supportedVersions,
                    Config::XML_PATH_PAYMENT_HANDLERS    => $paymentHandlers,
                    default                              => '',
                };
            }
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        $storeManager ??= $this->createStub(StoreManagerInterface::class);
        $logger       ??= new NullLogger();

        return new Config($scopeConfig, $storeManager, $logger);
    }
}
