<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RouterInterface;

/**
 * Routes the .well-known/ucp path to the UCP profile controller.
 *
 * Magento's default routing forbids dots in frontName values, so this custom
 * router catches /.well-known/ucp explicitly and dispatches it to the
 * Angeo\Ucp\Controller\WellKnown\Ucp action.
 *
 * Registered via di.xml as a `standard` router with sortOrder=22 so it runs
 * before the cms router (which would otherwise return 404).
 */
class Router implements RouterInterface
{
    private const WELL_KNOWN_PATH = '/.well-known/ucp';

    private const TARGET_ACTION = 'angeo_ucp/wellknown/ucp';

    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $path = '/' . trim((string) $request->getPathInfo(), '/');

        if ($path !== self::WELL_KNOWN_PATH) {
            return null;
        }

        $request->setModuleName('angeo_ucp')
            ->setControllerName('wellknown')
            ->setActionName('ucp');

        return $this->actionFactory->create(
            \Angeo\Ucp\Controller\WellKnown\Ucp::class
        );
    }
}
