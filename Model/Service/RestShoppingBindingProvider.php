<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Service;

use Angeo\Ucp\Api\ServiceBindingProviderInterface;
use Angeo\Ucp\Model\Config;

/**
 * REST transport binding for the dev.ucp.shopping service.
 *
 * Extracted from ProfileGenerator::buildServices() (pre-1.4.0) unchanged:
 * emits a single `rest` binding when a REST endpoint is available
 * (explicitly configured, or derived from the store base URL).
 */
class RestShoppingBindingProvider implements ServiceBindingProviderInterface
{
    private const SPEC_BASE = 'https://ucp.dev/2026-04-08';

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
                    'spec'      => self::SPEC_BASE . '/specification/overview',
                    'transport' => 'rest',
                    'endpoint'  => $endpoint,
                    'schema'    => self::SPEC_BASE . '/services/shopping/rest.openapi.json',
                ],
            ],
        ];
    }
}
