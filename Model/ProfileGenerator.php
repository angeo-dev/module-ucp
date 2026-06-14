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
 * Verified against the live spec at https://ucp.dev/2026-04-08/specification/:
 *
 *  - Catalog is split into TWO granular capabilities:
 *      dev.ucp.shopping.catalog.search  (spec /specification/catalog/search/)
 *      dev.ucp.shopping.catalog.lookup  (spec /specification/catalog/lookup/)
 *    A single "dev.ucp.shopping.catalog" capability does not exist in the spec.
 *
 *  - Extensions declare their parent(s) via `extends` and MUST NOT be
 *    advertised when no parent capability is in the profile (the spec's
 *    "prune orphaned extensions" rule applies to negotiation; advertising an
 *    orphan would always be pruned, so we never emit one):
 *      dev.ucp.shopping.fulfillment  extends checkout
 *      dev.ucp.shopping.discount     extends checkout and/or cart (multi-parent)
 *
 *  - identity_linking lives under dev.ucp.common and MAY carry config.scopes.
 *
 *  - supported_versions (map version → profile URI) SHOULD be published by
 *    businesses that support older protocol versions.
 *
 *  - payment_handlers MAY be declared at the same level as capabilities.
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

        $ucp = [
            'version'      => $version,
            'services'     => $this->buildServices($version),
            'capabilities' => $this->buildCapabilities($version),
        ];

        $paymentHandlers = $this->config->getPaymentHandlers();
        if ($paymentHandlers !== []) {
            $ucp['payment_handlers'] = $paymentHandlers;
        }

        $supportedVersions = $this->config->getSupportedVersions();
        if ($supportedVersions !== []) {
            $ucp['supported_versions'] = $supportedVersions;
        }

        $profile = ['ucp' => $ucp];

        $keys = $this->config->getPublicSigningKeys();
        if ($keys !== []) {
            $profile['signing_keys'] = $keys;
        }

        return $profile;
    }

    /**
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
                    'version'   => $version,
                    'spec'      => self::SPEC_BASE . '/specification/overview',
                    'transport' => 'rest',
                    'endpoint'  => $endpoint,
                    'schema'    => self::SPEC_BASE . '/services/shopping/rest.openapi.json',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildCapabilities(string $version): array
    {
        $capabilities = [];

        // ── Root capabilities ────────────────────────────────────────────────

        if ($this->config->isCatalogSearchDeclared()) {
            $capabilities['dev.ucp.shopping.catalog.search'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/catalog/search',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/catalog_search.json',
            ]];
        }

        if ($this->config->isCatalogLookupDeclared()) {
            $capabilities['dev.ucp.shopping.catalog.lookup'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/catalog/lookup',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/catalog_lookup.json',
            ]];
        }

        $cartDeclared = $this->config->isCartDeclared();
        if ($cartDeclared) {
            $capabilities['dev.ucp.shopping.cart'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/cart',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/cart.json',
            ]];
        }

        $checkoutDeclared = $this->config->isCheckoutDeclared();
        if ($checkoutDeclared) {
            $capabilities['dev.ucp.shopping.checkout'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/checkout',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/checkout.json',
            ]];
        }

        if ($this->config->isOrderDeclared()) {
            $capabilities['dev.ucp.shopping.order'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/order',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/order.json',
            ]];
        }

        if ($this->config->isIdentityLinkingDeclared()) {
            $identity = [
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/identity-linking',
                'schema'  => self::SPEC_BASE . '/schemas/common/identity_linking.json',
            ];

            $scopes = $this->config->getIdentityLinkingScopes();
            if ($scopes !== []) {
                $scopeMap = [];
                foreach ($scopes as $scope) {
                    $scopeMap[$scope] = new \stdClass();
                }
                $identity['config'] = ['scopes' => $scopeMap];
            }

            $capabilities['dev.ucp.common.identity_linking'] = [$identity];
        }

        // ── Extensions (never advertised without a parent — orphaned
        //    extensions are always pruned during negotiation, so emitting
        //    them would be spec-noncompliant noise) ───────────────────────────

        if ($this->config->isFulfillmentDeclared() && $checkoutDeclared) {
            $capabilities['dev.ucp.shopping.fulfillment'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/fulfillment',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/fulfillment.json',
                'extends' => 'dev.ucp.shopping.checkout',
            ]];
        }

        if ($this->config->isDiscountDeclared() && ($checkoutDeclared || $cartDeclared)) {
            $parents = [];
            if ($checkoutDeclared) {
                $parents[] = 'dev.ucp.shopping.checkout';
            }
            if ($cartDeclared) {
                $parents[] = 'dev.ucp.shopping.cart';
            }

            $capabilities['dev.ucp.shopping.discount'] = [[
                'version' => $version,
                'spec'    => self::SPEC_BASE . '/specification/discount',
                'schema'  => self::SPEC_BASE . '/schemas/shopping/discount.json',
                // Single parent → string; multiple parents → array (per spec).
                'extends' => count($parents) === 1 ? $parents[0] : $parents,
            ]];
        }

        return $capabilities;
    }
}
