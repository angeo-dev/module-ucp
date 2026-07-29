<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

use Angeo\Ucp\Api\ProfileGeneratorInterface;
use Angeo\Ucp\Api\ServiceBindingProviderInterface;

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
 * Verified against the official JSON Schemas in the spec repository
 * (Universal-Commerce-Protocol/ucp, tag v2026-04-08):
 *
 *  - business_schema REQUIRES the `services` and `payment_handlers` keys of
 *    the `ucp` object to be present, even when empty
 *    (source/schemas/ucp.json#/$defs/business_schema).
 *
 *  - `services`, `capabilities`, and `payment_handlers` are JSON OBJECTS
 *    keyed by reverse-domain name. Empty registries must serialize as `{}`,
 *    never `[]`.
 *
 *  - `signing_keys` is a TOP-LEVEL sibling of `ucp`
 *    (source/discovery/profile_schema.json#/$defs/base).
 *
 *  - each payment handler entry requires `id` and `version`
 *    (source/schemas/payment_handler.json#/$defs/base).
 */
class ProfileGenerator implements ProfileGeneratorInterface
{
    private const SPEC_BASE = 'https://ucp.dev/2026-04-08';

    /**
     * @param ServiceBindingProviderInterface[] $serviceBindingProviders
     *        Pool of transport binding providers collected via di.xml.
     *        Third-party modules can contribute additional services or
     *        transports without modifying this class.
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $serviceBindingProviders = []
    ) {
    }

    public function generate(): array
    {
        $version = Config::PROTOCOL_VERSION;

        // Per the official business profile schema (ucp.json#/$defs/business_schema,
        // spec repo tag v2026-04-08), `services` AND `payment_handlers` are
        // REQUIRED keys of the ucp object — they must be present even when
        // empty. All three registries are JSON *objects* keyed by
        // reverse-domain name, so empty registries must serialize as `{}`
        // (stdClass), never as `[]` (a PHP empty array would JSON-encode as
        // an array and fail schema validation).
        $ucp = [
            'version'          => $version,
            'services'         => self::asJsonObject($this->buildServices($version)),
            'capabilities'     => self::asJsonObject($this->buildCapabilities($version)),
            'payment_handlers' => self::asJsonObject($this->config->getPaymentHandlers()),
        ];

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
     * Ensure a registry map serializes as a JSON object.
     *
     * Empty PHP arrays JSON-encode as `[]`, but the UCP profile schema
     * requires `services`, `capabilities`, and `payment_handlers` to be
     * objects. Non-empty maps keep their string keys and encode correctly.
     *
     * @param array<string, mixed> $map
     */
    private static function asJsonObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }

    /**
     * Merge registry fragments from all binding providers.
     *
     * Bindings from multiple providers for the same service name are
     * APPENDED into one array — the spec models each transport binding as
     * a separate entry in the service array (schemas/service.json), so a
     * store exposing both REST and MCP publishes:
     *
     *   "dev.ucp.shopping": [ {transport: "rest", ...}, {transport: "mcp", ...} ]
     *
     * Providers that are not configured return [] and are skipped. Non
     * spec-compliant fragments (non-reverse-domain keys, non-list values)
     * are dropped defensively; `angeo:ucp:validate` reports the details.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildServices(string $version): array
    {
        $services = [];

        foreach ($this->serviceBindingProviders as $provider) {
            if (!$provider instanceof ServiceBindingProviderInterface) {
                continue;
            }
            foreach ($provider->getBindings($version) as $serviceName => $bindings) {
                if (!is_string($serviceName)
                    || !preg_match(Config::REVERSE_DOMAIN_PATTERN, $serviceName)
                    || !is_array($bindings)
                    || !array_is_list($bindings)
                ) {
                    continue;
                }
                foreach ($bindings as $binding) {
                    if (is_array($binding)) {
                        $services[$serviceName][] = $binding;
                    }
                }
            }
        }

        return $services;
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
