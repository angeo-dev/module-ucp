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
 * REST transport binding for the dev.ucp.shopping service.
 *
 * Emits a single `rest` binding when a REST endpoint is available
 * (explicitly configured, or derived from the store base URL).
 *
 * 2.0.0: the spec/schema URLs now come from Model\Spec, which pins them to
 * the 2026-08-25 tree. Per the service.json description at that tag,
 * `version` identifies the SERVICE, not the transport — the binding has no
 * separate version, and the OpenAPI artifact's own info.version is release
 * metadata rather than something to negotiate.
 */
class RestShoppingBindingProvider implements ServiceBindingProviderInterface
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function getBindings(string $protocolVersion): array
    {
        $endpoint = $this->config->getRestEndpoint();
        if ($endpoint === '') {
            return [];
        }

        return [
            Config::SHOPPING_SERVICE_NAME => [
                [
                    'version'   => $protocolVersion,
                    'spec'      => Spec::SERVICE_SPEC,
                    'transport' => 'rest',
                    'endpoint'  => $endpoint,
                    'schema'    => Spec::SERVICE_REST_SCHEMA,
                ],
            ],
        ];
    }
}
