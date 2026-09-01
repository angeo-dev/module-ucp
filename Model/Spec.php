<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

/**
 * Single source of truth for every spec/schema URL the profile publishes.
 *
 * UCP 2026-08-25 moved the documentation tree (catalog/cart/checkout/order
 * now live under /specification/shopping/, extensions under
 * /specification/shopping/extensions/) and made `schema` REQUIRED on every
 * capability in a business profile
 * (schemas/capability.json#/$defs/business_schema).
 *
 * The `schema` URL additionally carries an AUTHORITY BINDING (spec:
 * "Namespace Governance -> Authority Binding"): a platform MUST reject any
 * entity whose schema host, reversed, is not a label-aligned prefix of the
 * entity name. Every dev.ucp.* entity therefore MUST be served from ucp.dev.
 * That is why these URLs are built here and never taken from admin input.
 *
 * @see https://ucp.dev/2026-08-25/specification/overview/
 */
final class Spec
{
    /** Documentation root for the advertised protocol version. */
    public const BASE = 'https://ucp.dev/' . Config::PROTOCOL_VERSION;

    // ── Service bindings ─────────────────────────────────────────────────

    public const SERVICE_SPEC        = self::BASE . '/specification/overview/';
    public const SERVICE_REST_SCHEMA = self::BASE . '/services/shopping/rest.openapi.json';
    public const SERVICE_MCP_SCHEMA  = self::BASE . '/services/shopping/mcp.openrpc.json';

    // ── Capabilities: [spec URL, schema URL] ─────────────────────────────

    public const CAPABILITIES = [
        'dev.ucp.shopping.catalog.search' => [
            self::BASE . '/specification/shopping/catalog/search',
            self::BASE . '/schemas/shopping/catalog_search.json',
        ],
        'dev.ucp.shopping.catalog.lookup' => [
            self::BASE . '/specification/shopping/catalog/lookup',
            self::BASE . '/schemas/shopping/catalog_lookup.json',
        ],
        'dev.ucp.shopping.cart' => [
            self::BASE . '/specification/shopping/cart/',
            self::BASE . '/schemas/shopping/cart.json',
        ],
        'dev.ucp.shopping.checkout' => [
            self::BASE . '/specification/shopping/checkout/',
            self::BASE . '/schemas/shopping/checkout.json',
        ],
        'dev.ucp.shopping.order' => [
            self::BASE . '/specification/shopping/order/',
            self::BASE . '/schemas/shopping/order.json',
        ],
        'dev.ucp.shopping.permalink' => [
            self::BASE . '/specification/permalink',
            self::BASE . '/schemas/shopping/permalink.json',
        ],
        'dev.ucp.common.identity_linking' => [
            self::BASE . '/specification/common/identity-linking/',
            self::BASE . '/schemas/common/identity_linking.json',
        ],
        'dev.ucp.shopping.fulfillment' => [
            self::BASE . '/specification/shopping/extensions/fulfillment',
            self::BASE . '/schemas/shopping/fulfillment.json',
        ],
        'dev.ucp.shopping.discount' => [
            self::BASE . '/specification/shopping/extensions/discount',
            self::BASE . '/schemas/shopping/discount.json',
        ],
        'dev.ucp.shopping.buyer_consent' => [
            self::BASE . '/specification/shopping/extensions/buyer-consent',
            self::BASE . '/schemas/shopping/buyer_consent.json',
        ],
    ];

    /**
     * Capability entry with the REQUIRED version/spec/schema triple.
     *
     * @return array<string, string>
     */
    public static function capability(string $name, string $version): array
    {
        if (!isset(self::CAPABILITIES[$name])) {
            throw new \InvalidArgumentException('Unknown UCP capability: ' . $name);
        }

        [$spec, $schema] = self::CAPABILITIES[$name];

        return ['version' => $version, 'spec' => $spec, 'schema' => $schema];
    }

    private function __construct()
    {
    }
}
