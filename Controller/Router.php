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

        if (!$this->isWellKnownUcp($request)) {
            return null;
        }

        $request->setModuleName('angeo_ucp')
            ->setControllerName('wellknown')
            ->setActionName('ucp');

        return $this->actionFactory->create(
            \Angeo\Ucp\Controller\WellKnown\Ucp::class
        );
    }

    /**
     * Robustly decide whether the request targets /.well-known/ucp.
     *
     * Different web servers populate the path differently. On nginx/Apache
     * getPathInfo() is reliable, but LiteSpeed (Hostinger) often leaves
     * PATH_INFO empty for dot-segment paths, so getPathInfo() returns the wrong
     * value and a strict comparison fails — which is why the endpoint 404'd
     * there. We therefore check several path sources and accept a match from
     * any of them.
     */
    private function isWellKnownUcp(HttpRequest $request): bool
    {
        $candidates = [
            (string) $request->getPathInfo(),
            (string) $request->getOriginalPathInfo(),
            (string) $request->getRequestUri(),
        ];

        foreach ($candidates as $raw) {
            if ($raw === '') {
                continue;
            }

            // Drop query string and fragment. Trailing slashes are NOT
            // normalised away: `/.well-known/ucp/` is a different URL that
            // would serve identical bytes, so a CDN caches it separately and
            // the halved hit rate is paid on every agent fetch. 2.1.0 matches
            // the canonical form only; the variant is redirected instead
            // (see Controller\WellKnown\Ucp).
            $path = (string) parse_url($raw, PHP_URL_PATH);

            if ($path === self::WELL_KNOWN_PATH) {
                return true;
            }

            // Recognised so the controller can issue a 301, not so it can
            // serve a second copy.
            if (rtrim($path, '/') === self::WELL_KNOWN_PATH) {
                return true;
            }
        }

        return false;
    }
}
