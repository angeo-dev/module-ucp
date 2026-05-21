<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

use Angeo\Ucp\Api\ProfileGeneratorInterface;

/**
 * Builds the UCP business profile per spec version 2026-04-08.
 *
 * @see https://ucp.dev/latest/specification/overview/#business-profile
 *
 * v0.1.0 declares the dev.ucp.shopping REST service binding and exposes
 * capability toggles for catalog/cart/checkout/order/identity_linking.
 * Enabling a capability here ONLY adds it to the advertised profile —
 * the actual endpoints are not yet implemented and will arrive in later
 * releases. Sites should keep all capabilities disabled in v0.1.0 unless
 * they are testing discovery.
 */
class ProfileGenerator implements ProfileGeneratorInterface
{
    private const SHOPPING_SERVICE_NAME = 'dev.ucp.shopping';
    private const SPEC_BASE = 'https://ucp.dev/2026-04-08';

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function generate(): array
    {
        $version = Config::PROTOCOL_VERSION;

        $profile = [
            'ucp' => [
                'version' => $version,
                'services' => $this->buildServices($version),
                'capabilities' => $this->buildCapabilities($version),
            ],
        ];

        $keys = $this->config->getPublicSigningKeys();
        if ($keys !== []) {
            $profile['signing_keys'] = $keys;
        }

        return $profile;
    }

    /**
     * @param string $version
     * @return array<string, array<int, array<string, string>>>
     */
    private function buildServices(string $version): array
    {
        $endpoint = $this->config->getRestEndpoint();
        if ($endpoint === '') {
            return [];
        }

        return [
            self::SHOPPING_SERVICE_NAME => [
                [
                    'version' => $version,
                    'spec' => self::SPEC_BASE . '/specification/overview',
                    'transport' => 'rest',
                    'endpoint' => $endpoint,
                    'schema' => self::SPEC_BASE . '/services/shopping/rest.openapi.json',
                ],
            ],
        ];
    }

    /**
     * @param string $version
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildCapabilities(string $version): array
    {
        $capabilities = [];

        if ($this->config->isCatalogDeclared()) {
            $capabilities['dev.ucp.shopping.catalog'] = [[
                'version' => $version,
                'spec' => self::SPEC_BASE . '/specification/catalog',
                'schema' => self::SPEC_BASE . '/schemas/shopping/catalog.json',
            ]];
        }

        if ($this->config->isCartDeclared()) {
            $capabilities['dev.ucp.shopping.cart'] = [[
                'version' => $version,
                'spec' => self::SPEC_BASE . '/specification/cart',
                'schema' => self::SPEC_BASE . '/schemas/shopping/cart.json',
            ]];
        }

        if ($this->config->isCheckoutDeclared()) {
            $capabilities['dev.ucp.shopping.checkout'] = [[
                'version' => $version,
                'spec' => self::SPEC_BASE . '/specification/checkout',
                'schema' => self::SPEC_BASE . '/schemas/shopping/checkout.json',
            ]];
        }

        if ($this->config->isOrderDeclared()) {
            $capabilities['dev.ucp.shopping.order'] = [[
                'version' => $version,
                'spec' => self::SPEC_BASE . '/specification/order',
                'schema' => self::SPEC_BASE . '/schemas/shopping/order.json',
            ]];
        }

        if ($this->config->isIdentityLinkingDeclared()) {
            $capabilities['dev.ucp.common.identity_linking'] = [[
                'version' => $version,
                'spec' => self::SPEC_BASE . '/specification/identity-linking',
                'schema' => self::SPEC_BASE . '/schemas/common/identity_linking.json',
            ]];
        }

        return $capabilities;
    }
}
