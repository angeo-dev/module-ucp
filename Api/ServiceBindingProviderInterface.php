<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Api;

/**
 * Contributes transport bindings to the `ucp.services` registry of the
 * business profile.
 *
 * Implementations are collected via di.xml into the ProfileGenerator's
 * provider pool. Each provider returns a registry FRAGMENT:
 *
 *   [
 *     'dev.ucp.shopping' => [
 *       ['version' => ..., 'transport' => 'rest', 'endpoint' => ..., ...],
 *     ],
 *   ]
 *
 * Fragments are merged by service name: bindings from multiple providers
 * for the same service are appended into one array, matching the spec's
 * model of "each transport binding is a separate entry in the service
 * array" (schemas/service.json).
 *
 * Third-party modules (e.g. a future catalog or vertical implementation)
 * can register their own providers via di.xml without modifying this
 * module:
 *
 *   <type name="Angeo\Ucp\Model\ProfileGenerator">
 *       <arguments>
 *           <argument name="serviceBindingProviders" xsi:type="array">
 *               <item name="my_provider" xsi:type="object">Vendor\Module\Model\MyProvider</item>
 *           </argument>
 *       </arguments>
 *   </type>
 *
 * Contract requirements (enforced downstream by `angeo:ucp:validate`):
 *  - service keys MUST be reverse-domain names;
 *  - every binding MUST carry `version` and `transport`;
 *  - `rest`, `mcp`, and `a2a` bindings MUST carry an HTTPS `endpoint`
 *    (schemas/service.json#/$defs/business_schema, spec tag v2026-04-08).
 *
 * A provider MAY return an empty array when its transport is not
 * configured; it is then simply skipped.
 *
 * @api
 */
interface ServiceBindingProviderInterface
{
    /**
     * @param string $protocolVersion UCP protocol version being advertised
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getBindings(string $protocolVersion): array;
}
