<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Service;

use Angeo\Ucp\Api\ServiceBindingProviderInterface;
use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\Spec;

/**
 * MCP transport binding for the dev.ucp.shopping service.
 *
 * Per schemas/service.json (spec tag v2026-04-08), `mcp` is a first-class
 * transport alongside rest/a2a/embedded, and — like rest — REQUIRES an
 * `endpoint` in business profiles. Unlike the REST binding, the MCP
 * endpoint is NEVER derived from the store base URL: it is only advertised
 * when explicitly configured, because an MCP server (e.g.
 * angeo/module-mcp-server's Streamable HTTP endpoint) is a separate,
 * opt-in piece of infrastructure.
 */
class McpShoppingBindingProvider implements ServiceBindingProviderInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getBindings(string $protocolVersion): array
    {
        $endpoint = $this->config->getMcpEndpoint();
        if ($endpoint === '') {
            return [];
        }

        return [
            Config::SHOPPING_SERVICE_NAME => [
                [
                    'version'   => $protocolVersion,
                    'spec'      => Spec::SERVICE_SPEC,
                    'transport' => 'mcp',
                    'endpoint'  => $endpoint,
                    // 2.0.0: the MCP binding now declares its OpenRPC schema
                    // too. 1.4.0 omitted it, which left agents with no
                    // machine-readable description of the MCP surface — the
                    // spec's own business-profile example declares one on
                    // every transport that defines it.
                    'schema'    => Spec::SERVICE_MCP_SCHEMA,
                ],
            ],
        ];
    }
}
