<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\ProfileGenerator;
use Angeo\Ucp\Model\Service\McpShoppingBindingProvider;
use Angeo\Ucp\Model\Service\RestShoppingBindingProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProfileGenerator::class)]
final class ProfileGeneratorTest extends TestCase
{
    private const ENDPOINT = 'https://shop.example.com/rest/V1/ucp';

    #[Test]
    public function profile_declares_spec_compliant_protocol_version(): void
    {
        $profile = $this->generate();

        self::assertSame('2026-04-08', $profile['ucp']['version']);
    }

    #[Test]
    public function profile_includes_rest_service_binding(): void
    {
        $profile  = $this->generate();
        $services = $profile['ucp']['services'];

        self::assertArrayHasKey('dev.ucp.shopping', $services);
        self::assertCount(1, $services['dev.ucp.shopping']);

        $rest = $services['dev.ucp.shopping'][0];
        self::assertSame('rest', $rest['transport']);
        self::assertSame(self::ENDPOINT, $rest['endpoint']);
        self::assertSame('2026-04-08', $rest['version']);
        self::assertStringStartsWith('https://ucp.dev/2026-04-08/', $rest['spec']);
        self::assertStringStartsWith('https://ucp.dev/2026-04-08/', $rest['schema']);
    }

    #[Test]
    public function empty_services_registry_is_present_and_serializes_as_json_object(): void
    {
        // Per ucp.json#/$defs/business_schema, `services` is a REQUIRED key
        // and registries are JSON objects — an empty registry must encode
        // as {} rather than [].
        $profile = $this->generate(endpoint: '');

        self::assertArrayHasKey('services', $profile['ucp']);
        self::assertInstanceOf(\stdClass::class, $profile['ucp']['services']);
        self::assertStringContainsString(
            '"services":{}',
            json_encode($profile, JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function empty_capabilities_registry_serializes_as_json_object(): void
    {
        $profile = $this->generate();

        self::assertArrayHasKey('capabilities', $profile['ucp']);
        self::assertInstanceOf(\stdClass::class, $profile['ucp']['capabilities']);
        self::assertStringContainsString(
            '"capabilities":{}',
            json_encode($profile, JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function payment_handlers_key_is_always_present_even_when_empty(): void
    {
        // business_schema REQUIRES the payment_handlers key; empty must be {}.
        $profile = $this->generate();

        self::assertArrayHasKey('payment_handlers', $profile['ucp']);
        self::assertInstanceOf(\stdClass::class, $profile['ucp']['payment_handlers']);
        self::assertStringContainsString(
            '"payment_handlers":{}',
            json_encode($profile, JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function catalog_is_split_into_granular_search_and_lookup_capabilities(): void
    {
        // Per spec 2026-04-08, catalog is NOT a single capability —
        // it is dev.ucp.shopping.catalog.search + dev.ucp.shopping.catalog.lookup.
        $profile = $this->generate(catalogSearch: true, catalogLookup: true);
        $caps    = $profile['ucp']['capabilities'];

        self::assertArrayHasKey('dev.ucp.shopping.catalog.search', $caps);
        self::assertArrayHasKey('dev.ucp.shopping.catalog.lookup', $caps);
        self::assertArrayNotHasKey('dev.ucp.shopping.catalog', $caps);

        $search = $caps['dev.ucp.shopping.catalog.search'][0];
        self::assertSame(
            'https://ucp.dev/2026-04-08/specification/catalog/search',
            $search['spec']
        );
        self::assertSame(
            'https://ucp.dev/2026-04-08/schemas/shopping/catalog_search.json',
            $search['schema']
        );

        $lookup = $caps['dev.ucp.shopping.catalog.lookup'][0];
        self::assertSame(
            'https://ucp.dev/2026-04-08/specification/catalog/lookup',
            $lookup['spec']
        );
        self::assertSame(
            'https://ucp.dev/2026-04-08/schemas/shopping/catalog_lookup.json',
            $lookup['schema']
        );
    }

    #[Test]
    public function catalog_capabilities_can_be_declared_independently(): void
    {
        $profile = $this->generate(catalogSearch: true, catalogLookup: false);
        $caps    = $profile['ucp']['capabilities'];

        self::assertArrayHasKey('dev.ucp.shopping.catalog.search', $caps);
        self::assertArrayNotHasKey('dev.ucp.shopping.catalog.lookup', $caps);
    }

    #[Test]
    public function identity_linking_uses_common_namespace_not_shopping(): void
    {
        $profile = $this->generate(identityLinking: true);
        $caps    = $profile['ucp']['capabilities'];

        self::assertArrayHasKey('dev.ucp.common.identity_linking', $caps);
        self::assertArrayNotHasKey('dev.ucp.shopping.identity_linking', $caps);
    }

    #[Test]
    public function identity_linking_includes_scopes_config_when_configured(): void
    {
        $profile = $this->generate(
            identityLinking: true,
            identityScopes: ['dev.ucp.shopping.order:read', 'dev.ucp.shopping.order:manage']
        );

        $identity = $profile['ucp']['capabilities']['dev.ucp.common.identity_linking'][0];

        self::assertArrayHasKey('config', $identity);
        self::assertArrayHasKey('scopes', $identity['config']);
        self::assertArrayHasKey('dev.ucp.shopping.order:read', $identity['config']['scopes']);
        self::assertArrayHasKey('dev.ucp.shopping.order:manage', $identity['config']['scopes']);

        // Scope values must encode as empty JSON objects {}, not arrays [].
        $encoded = json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"dev.ucp.shopping.order:read":{}', $encoded);
    }

    #[Test]
    public function identity_linking_omits_config_when_no_scopes(): void
    {
        $profile  = $this->generate(identityLinking: true);
        $identity = $profile['ucp']['capabilities']['dev.ucp.common.identity_linking'][0];

        self::assertArrayNotHasKey('config', $identity);
    }

    #[Test]
    public function fulfillment_extension_declares_checkout_parent(): void
    {
        $profile = $this->generate(checkout: true, fulfillment: true);
        $caps    = $profile['ucp']['capabilities'];

        self::assertArrayHasKey('dev.ucp.shopping.fulfillment', $caps);
        self::assertSame(
            'dev.ucp.shopping.checkout',
            $caps['dev.ucp.shopping.fulfillment'][0]['extends']
        );
    }

    #[Test]
    public function fulfillment_extension_is_pruned_when_checkout_disabled(): void
    {
        // Orphaned extensions must never be advertised. With no other
        // capability enabled the registry prunes to empty — which must
        // serialize as a JSON object.
        $profile = $this->generate(checkout: false, fulfillment: true);

        self::assertArrayNotHasKey(
            'dev.ucp.shopping.fulfillment',
            self::caps($profile)
        );
    }

    #[Test]
    public function discount_extension_uses_string_extends_for_single_parent(): void
    {
        $profile = $this->generate(checkout: true, discount: true);
        $caps    = $profile['ucp']['capabilities'];

        self::assertSame(
            'dev.ucp.shopping.checkout',
            $caps['dev.ucp.shopping.discount'][0]['extends']
        );
    }

    #[Test]
    public function discount_extension_uses_array_extends_for_multiple_parents(): void
    {
        $profile = $this->generate(checkout: true, cart: true, discount: true);
        $caps    = $profile['ucp']['capabilities'];

        self::assertSame(
            ['dev.ucp.shopping.checkout', 'dev.ucp.shopping.cart'],
            $caps['dev.ucp.shopping.discount'][0]['extends']
        );
    }

    #[Test]
    public function discount_extension_is_pruned_when_no_parent_enabled(): void
    {
        $profile = $this->generate(discount: true);

        self::assertArrayNotHasKey(
            'dev.ucp.shopping.discount',
            self::caps($profile)
        );
    }

    #[Test]
    public function profile_includes_supported_versions_when_configured(): void
    {
        $versions = ['2026-01-23' => 'https://shop.example.com/ucp/profiles/2026-01-23.json'];
        $profile  = $this->generate(supportedVersions: $versions);

        self::assertSame($versions, $profile['ucp']['supported_versions']);
    }

    #[Test]
    public function profile_omits_supported_versions_when_empty(): void
    {
        $profile = $this->generate();

        self::assertArrayNotHasKey('supported_versions', $profile['ucp']);
    }

    #[Test]
    public function profile_includes_payment_handlers_when_configured(): void
    {
        $handlers = [
            'com.example.tokenizer' => [[
                'id'      => 'merchant_tokenizer',
                'version' => '2026-04-08',
                'spec'    => 'https://example.com/specs/tokenizer',
                'schema'  => 'https://example.com/schemas/tokenizer.json',
            ]],
        ];
        $profile = $this->generate(paymentHandlers: $handlers);

        self::assertSame($handlers, $profile['ucp']['payment_handlers']);
    }

    #[Test]
    public function profile_includes_signing_keys_when_configured(): void
    {
        $jwk = [
            'kid' => 'angeo-ucp-2026-abcd1234',
            'kty' => 'EC',
            'crv' => 'P-256',
            'x'   => 'WbbXwVYGdJoP4Xm3qCkGvBRcRvKtEfXDbWvPzpPS8LA',
            'y'   => 'sP4jHHxYqC89HBo8TjrtVOAGHfJDflYxw7MFMxuFMPY',
            'use' => 'sig',
            'alg' => 'ES256',
        ];
        $profile = $this->generate(signingKeys: [$jwk]);

        self::assertArrayHasKey('signing_keys', $profile);
        self::assertSame([$jwk], $profile['signing_keys']);
    }

    #[Test]
    public function profile_omits_signing_keys_key_when_none_configured(): void
    {
        $profile = $this->generate();

        self::assertArrayNotHasKey('signing_keys', $profile);
    }

    #[Test]
    public function full_profile_is_json_encodable_under_strict_mode(): void
    {
        $profile = $this->generate(
            catalogSearch: true,
            catalogLookup: true,
            cart: true,
            checkout: true,
            order: true,
            identityLinking: true,
            identityScopes: ['dev.ucp.shopping.order:read'],
            fulfillment: true,
            discount: true
        );

        $encoded = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertIsString($encoded);
        self::assertJson($encoded);
        self::assertStringNotContainsString('\\/', $encoded, 'URLs must not be escaped.');
        self::assertCount(8, $profile['ucp']['capabilities']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    #[Test]
    public function mcp_endpoint_adds_mcp_binding_alongside_rest(): void
    {
        // schemas/service.json: each transport binding is a separate entry
        // in the SAME service array.
        $profile = $this->generate(mcpEndpoint: 'https://shop.example.com/mcp');

        $bindings = $profile['ucp']['services']['dev.ucp.shopping'];

        self::assertCount(2, $bindings);
        self::assertSame(['rest', 'mcp'], array_column($bindings, 'transport'));
        self::assertSame('https://shop.example.com/mcp', $bindings[1]['endpoint']);
        self::assertSame(Config::PROTOCOL_VERSION, $bindings[1]['version']);
    }

    #[Test]
    public function mcp_binding_alone_is_emitted_when_rest_endpoint_is_blank(): void
    {
        $profile = $this->generate(
            endpoint: '',
            mcpEndpoint: 'https://shop.example.com/mcp'
        );

        $bindings = $profile['ucp']['services']['dev.ucp.shopping'];

        self::assertCount(1, $bindings);
        self::assertSame('mcp', $bindings[0]['transport']);
    }

    #[Test]
    public function no_mcp_binding_without_configured_mcp_endpoint(): void
    {
        // MCP is opt-in only: never derived from the base URL.
        $profile = $this->generate();

        $transports = array_column(
            $profile['ucp']['services']['dev.ucp.shopping'],
            'transport'
        );

        self::assertNotContains('mcp', $transports);
    }

    #[Test]
    public function malformed_provider_fragments_are_dropped_defensively(): void
    {
        $stub = $this->createStub(Config::class);
        $stub->method('getPaymentHandlers')->willReturn([]);
        $stub->method('getSupportedVersions')->willReturn([]);
        $stub->method('getPublicSigningKeys')->willReturn([]);

        $badProvider = new class implements \Angeo\Ucp\Api\ServiceBindingProviderInterface {
            public function getBindings(string $protocolVersion): array
            {
                return [
                    'NotReverseDomain' => [['transport' => 'rest']],
                    'dev.ucp.shopping' => ['not-a-binding-object'],
                ];
            }
        };

        $profile = (new ProfileGenerator($stub, [$badProvider]))->generate();

        self::assertInstanceOf(\stdClass::class, $profile['ucp']['services']);
    }

    /**
     * Normalise the capabilities registry (array when populated, stdClass
     * when empty) to an array for assertions.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function caps(array $profile): array
    {
        $caps = $profile['ucp']['capabilities'];
        return $caps instanceof \stdClass ? (array) $caps : $caps;
    }

    /**
     * @param array<string, string> $supportedVersions
     * @param array<string, mixed>  $paymentHandlers
     * @param array<int, array<string, string>> $signingKeys
     * @param array<int, string>    $identityScopes
     */
    private function generate(
        string $endpoint = self::ENDPOINT,
        bool $catalogSearch = false,
        bool $catalogLookup = false,
        bool $cart = false,
        bool $checkout = false,
        bool $order = false,
        bool $identityLinking = false,
        string $mcpEndpoint = '',
        array $identityScopes = [],
        bool $fulfillment = false,
        bool $discount = false,
        array $supportedVersions = [],
        array $paymentHandlers = [],
        array $signingKeys = []
    ): array {
        $stub = $this->createStub(Config::class);
        $stub->method('isEnabled')->willReturn(true);
        $stub->method('getRestEndpoint')->willReturn($endpoint);
        $stub->method('getMcpEndpoint')->willReturn($mcpEndpoint);
        $stub->method('isCatalogSearchDeclared')->willReturn($catalogSearch);
        $stub->method('isCatalogLookupDeclared')->willReturn($catalogLookup);
        $stub->method('isCartDeclared')->willReturn($cart);
        $stub->method('isCheckoutDeclared')->willReturn($checkout);
        $stub->method('isOrderDeclared')->willReturn($order);
        $stub->method('isIdentityLinkingDeclared')->willReturn($identityLinking);
        $stub->method('getIdentityLinkingScopes')->willReturn($identityScopes);
        $stub->method('isFulfillmentDeclared')->willReturn($fulfillment);
        $stub->method('isDiscountDeclared')->willReturn($discount);
        $stub->method('getSupportedVersions')->willReturn($supportedVersions);
        $stub->method('getPaymentHandlers')->willReturn($paymentHandlers);
        $stub->method('getPublicSigningKeys')->willReturn($signingKeys);

        // Same provider pool shape as etc/di.xml wires in production.
        $providers = [
            new RestShoppingBindingProvider($stub),
            new McpShoppingBindingProvider($stub),
        ];

        return (new ProfileGenerator($stub, $providers))->generate();
    }
}
