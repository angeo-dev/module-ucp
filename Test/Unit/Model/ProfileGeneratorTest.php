<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\ProfileGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileGenerator::class)]
final class ProfileGeneratorTest extends TestCase
{
    #[Test]
    public function profile_declares_spec_compliant_protocol_version(): void
    {
        $generator = new ProfileGenerator($this->config(endpoint: 'https://shop.example.com/rest/V1/ucp'));

        $profile = $generator->generate();

        self::assertSame('2026-04-08', $profile['ucp']['version']);
    }

    #[Test]
    public function profile_includes_rest_service_binding(): void
    {
        $endpoint = 'https://shop.example.com/rest/V1/ucp';
        $generator = new ProfileGenerator($this->config(endpoint: $endpoint));

        $profile = $generator->generate();
        $services = $profile['ucp']['services'];

        self::assertArrayHasKey('dev.ucp.shopping', $services);
        self::assertCount(1, $services['dev.ucp.shopping']);

        $rest = $services['dev.ucp.shopping'][0];
        self::assertSame('rest', $rest['transport']);
        self::assertSame($endpoint, $rest['endpoint']);
        self::assertSame('2026-04-08', $rest['version']);
        self::assertStringStartsWith('https://ucp.dev/2026-04-08/', $rest['spec']);
        self::assertStringStartsWith('https://ucp.dev/2026-04-08/', $rest['schema']);
    }

    #[Test]
    public function profile_omits_services_when_endpoint_is_blank(): void
    {
        $generator = new ProfileGenerator($this->config(endpoint: ''));

        $profile = $generator->generate();

        self::assertSame([], $profile['ucp']['services']);
    }

    #[Test]
    public function profile_omits_disabled_capabilities(): void
    {
        $generator = new ProfileGenerator($this->config(endpoint: 'https://shop.example.com/rest/V1/ucp'));

        $profile = $generator->generate();

        self::assertSame([], $profile['ucp']['capabilities']);
    }

    #[Test]
    public function profile_includes_enabled_capabilities_with_namespace_prefix(): void
    {
        $generator = new ProfileGenerator($this->config(
            endpoint: 'https://shop.example.com/rest/V1/ucp',
            catalog: true,
            cart: true
        ));

        $profile = $generator->generate();

        self::assertArrayHasKey('dev.ucp.shopping.catalog', $profile['ucp']['capabilities']);
        self::assertArrayHasKey('dev.ucp.shopping.cart', $profile['ucp']['capabilities']);
        self::assertArrayNotHasKey('dev.ucp.shopping.checkout', $profile['ucp']['capabilities']);
    }

    #[Test]
    public function identity_linking_uses_common_namespace_not_shopping(): void
    {
        $generator = new ProfileGenerator($this->config(
            endpoint: 'https://shop.example.com/rest/V1/ucp',
            identityLinking: true
        ));

        $profile = $generator->generate();

        // Per spec, identity_linking lives under dev.ucp.common, NOT dev.ucp.shopping.
        self::assertArrayHasKey('dev.ucp.common.identity_linking', $profile['ucp']['capabilities']);
        self::assertArrayNotHasKey('dev.ucp.shopping.identity_linking', $profile['ucp']['capabilities']);
    }

    #[Test]
    public function profile_includes_signing_keys_when_configured(): void
    {
        $jwk = [
            'kid' => 'angeo-ucp-2026-abcd',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => 'WbbXwVYGdJoP4Xm3qCkGvBRcRvKtEfXDbWvPzpPS8LA',
            'y' => 'sP4jHHxYqC89HBo8TjrtVOAGHfJDflYxw7MFMxuFMPY',
            'use' => 'sig',
            'alg' => 'ES256',
        ];
        $generator = new ProfileGenerator($this->config(
            endpoint: 'https://shop.example.com/rest/V1/ucp',
            signingKeys: [$jwk]
        ));

        $profile = $generator->generate();

        self::assertArrayHasKey('signing_keys', $profile);
        self::assertSame([$jwk], $profile['signing_keys']);
    }

    #[Test]
    public function profile_omits_signing_keys_key_when_none_configured(): void
    {
        $generator = new ProfileGenerator($this->config(endpoint: 'https://shop.example.com/rest/V1/ucp'));

        $profile = $generator->generate();

        self::assertArrayNotHasKey('signing_keys', $profile);
    }

    #[Test]
    public function profile_is_json_encodable_under_strict_mode(): void
    {
        $generator = new ProfileGenerator($this->config(
            endpoint: 'https://shop.example.com/rest/V1/ucp',
            catalog: true,
            cart: true,
            checkout: true,
            order: true,
            identityLinking: true
        ));

        $profile = $generator->generate();
        $encoded = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertIsString($encoded);
        self::assertJson($encoded);
        self::assertStringNotContainsString('\\/', $encoded, 'URLs must not be escaped.');
    }

    #[Test]
    public function all_five_capabilities_can_be_declared_simultaneously(): void
    {
        $generator = new ProfileGenerator($this->config(
            endpoint: 'https://shop.example.com/rest/V1/ucp',
            catalog: true,
            cart: true,
            checkout: true,
            order: true,
            identityLinking: true
        ));

        $profile = $generator->generate();

        self::assertCount(5, $profile['ucp']['capabilities']);
    }

    /**
     * Build a Config stub returning the requested values. Using a real
     * stub class (not PHPUnit's createStub) so that strict-typed property
     * access works under PHP 8.2 without proxy overhead.
     */
    private function config(
        string $endpoint,
        bool $enabled = true,
        bool $catalog = false,
        bool $cart = false,
        bool $checkout = false,
        bool $order = false,
        bool $identityLinking = false,
        array $signingKeys = []
    ): Config {
        $stub = $this->createStub(Config::class);
        $stub->method('isEnabled')->willReturn($enabled);
        $stub->method('getRestEndpoint')->willReturn($endpoint);
        $stub->method('isCatalogDeclared')->willReturn($catalog);
        $stub->method('isCartDeclared')->willReturn($cart);
        $stub->method('isCheckoutDeclared')->willReturn($checkout);
        $stub->method('isOrderDeclared')->willReturn($order);
        $stub->method('isIdentityLinkingDeclared')->willReturn($identityLinking);
        $stub->method('getPublicSigningKeys')->willReturn($signingKeys);
        return $stub;
    }
}
