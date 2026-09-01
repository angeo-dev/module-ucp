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
 * Builds the UCP business profile per spec version 2026-08-25.
 *
 * What changed versus the 2026-04-08 profile this class produced in 1.x
 * (all verified against Universal-Commerce-Protocol/ucp at tag v2026-08-25):
 *
 *  - SIGNING KEYS MOVED. The canonical field is now the top-level `keys[]`
 *    array, an RFC 7517 JWK Set (schemas/profile.json#/$defs/base). The
 *    1.x field `signing_keys` is no longer read by any verifier, so a
 *    profile that still uses it publishes no usable keys at all. The
 *    overview is explicit that a key is not effectively revoked until it is
 *    absent from `keys[]` — which also means it is not effectively
 *    PUBLISHED until it is present there.
 *
 *  - DOCUMENTATION TREE MOVED. Catalog, cart, checkout and order now sit
 *    under /specification/shopping/; fulfillment, discount and the new
 *    buyer_consent under /specification/shopping/extensions/. All URLs are
 *    centralised in Model\Spec.
 *
 *  - `schema` IS NOW REQUIRED on every business capability
 *    (capability.json#/$defs/business_schema adds required: ["schema"]),
 *    because platforms fetch and compose it during negotiation. Every entry
 *    this class emits carries one.
 *
 *  - AUTHORITY BINDING is now a MUST: a platform rejects any entity whose
 *    `schema` host, label-reversed, is not a prefix of the entity name. All
 *    dev.ucp.* schema URLs are therefore hard-coded to ucp.dev rather than
 *    derived from configuration. See Model\AuthorityBinding.
 *
 *  - NEW ENTRIES: dev.ucp.shopping.permalink (root capability, carries
 *    config.endpoint) and dev.ucp.shopping.buyer_consent (extension of
 *    cart and/or checkout).
 *
 * Unchanged from 1.x and re-verified at the new tag:
 *
 *  - business_schema still REQUIRES the `services` and `payment_handlers`
 *    keys of the `ucp` object even when empty (ucp.json#/$defs/business_schema),
 *    and all three registries are JSON OBJECTS keyed by reverse-domain name,
 *    so empty registries must serialize as `{}` and never `[]`.
 *
 *  - `capabilities` remains OPTIONAL; extensions are still pruned when
 *    orphaned; identity_linking still lives under dev.ucp.common with
 *    config.scopes as an object map.
 */
class ProfileGenerator implements ProfileGeneratorInterface
{
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

        // Spec 2026-08-25: signing keys are published in the top-level
        // `keys[]` JWK Set. This is the ONLY field UCP verifiers read, and
        // it also makes the profile document a valid RFC 7517 JWK Set that
        // a signer can reuse as its Web Bot Auth key source.
        $keys = $this->config->getPublicKeys();
        if ($keys !== []) {
            $profile['keys'] = $keys;
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
            $capabilities['dev.ucp.shopping.catalog.search'] =
                [Spec::capability('dev.ucp.shopping.catalog.search', $version)];
        }

        if ($this->config->isCatalogLookupDeclared()) {
            $capabilities['dev.ucp.shopping.catalog.lookup'] =
                [Spec::capability('dev.ucp.shopping.catalog.lookup', $version)];
        }

        $cartDeclared = $this->config->isCartDeclared();
        if ($cartDeclared) {
            $capabilities['dev.ucp.shopping.cart'] =
                [Spec::capability('dev.ucp.shopping.cart', $version)];
        }

        $checkoutDeclared = $this->config->isCheckoutDeclared();
        if ($checkoutDeclared) {
            $capabilities['dev.ucp.shopping.checkout'] =
                [Spec::capability('dev.ucp.shopping.checkout', $version)];
        }

        if ($this->config->isOrderDeclared()) {
            $capabilities['dev.ucp.shopping.order'] =
                [Spec::capability('dev.ucp.shopping.order', $version)];
        }

        // Permalink (new in 2026-08-25): a buyable URL an agent can hand to
        // the shopper. config.endpoint is where that URL is rooted; without
        // it the capability tells an agent nothing actionable, so it is only
        // advertised once an endpoint resolves.
        if ($this->config->isPermalinkDeclared()) {
            $permalinkEndpoint = $this->config->getPermalinkEndpoint();
            if ($permalinkEndpoint !== '') {
                $permalink = Spec::capability('dev.ucp.shopping.permalink', $version);
                $permalink['config'] = ['endpoint' => $permalinkEndpoint];
                $capabilities['dev.ucp.shopping.permalink'] = [$permalink];
            }
        }

        if ($this->config->isIdentityLinkingDeclared()) {
            $identity = Spec::capability('dev.ucp.common.identity_linking', $version);

            $scopes = $this->config->getIdentityLinkingScopes();
            if ($scopes !== []) {
                // Scope values encode as empty JSON objects, per the spec example.
                $scopeMap = [];
                foreach ($scopes as $scope) {
                    $scopeMap[$scope] = new \stdClass();
                }
                $identity['config'] = ['scopes' => $scopeMap];
            }

            $capabilities['dev.ucp.common.identity_linking'] = [$identity];
        }

        // ── Extensions ───────────────────────────────────────────────────────
        //
        // An extension is never advertised without a parent: negotiation
        // prunes orphans in every case, so emitting one is guaranteed dead
        // weight. `extends` is a string for a single parent and an array for
        // several, exactly as the spec examples show.

        if ($this->config->isFulfillmentDeclared() && $checkoutDeclared) {
            $fulfillment = Spec::capability('dev.ucp.shopping.fulfillment', $version);
            $fulfillment['extends'] = 'dev.ucp.shopping.checkout';
            $capabilities['dev.ucp.shopping.fulfillment'] = [$fulfillment];
        }

        $checkoutOrCart = self::parents($checkoutDeclared, $cartDeclared);

        if ($this->config->isDiscountDeclared() && $checkoutOrCart !== []) {
            $discount = Spec::capability('dev.ucp.shopping.discount', $version);
            $discount['extends'] = self::extendsValue($checkoutOrCart);
            $capabilities['dev.ucp.shopping.discount'] = [$discount];
        }

        // Buyer consent (new in 2026-08-25) extends cart and/or checkout.
        if ($this->config->isBuyerConsentDeclared() && $checkoutOrCart !== []) {
            $consent = Spec::capability('dev.ucp.shopping.buyer_consent', $version);
            $consent['extends'] = self::extendsValue($checkoutOrCart);
            $capabilities['dev.ucp.shopping.buyer_consent'] = [$consent];
        }

        return $capabilities;
    }

    /**
     * Parents shared by the discount and buyer_consent extensions.
     *
     * @return array<int, string>
     */
    private static function parents(bool $checkout, bool $cart): array
    {
        $parents = [];
        if ($checkout) {
            $parents[] = 'dev.ucp.shopping.checkout';
        }
        if ($cart) {
            $parents[] = 'dev.ucp.shopping.cart';
        }

        return $parents;
    }

    /**
     * Single parent encodes as a string, several as an array (spec:
     * capability.json#/$defs/business_schema `extends` is a oneOf).
     *
     * @param array<int, string> $parents
     */
    private static function extendsValue(array $parents): string|array
    {
        return count($parents) === 1 ? $parents[0] : $parents;
    }
}
