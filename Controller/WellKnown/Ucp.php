<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Controller\WellKnown;

use Angeo\Ucp\Api\ProfileGeneratorInterface;
use Angeo\Ucp\Model\Config;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves /.well-known/ucp.
 *
 * Response MUST:
 *  - be served over HTTPS (handled by web server)
 *  - return Content-Type: application/json
 *  - return Cache-Control with public + max-age >= 60 (per UCP spec)
 *  - NOT use 3xx redirects (handled by web server)
 *  - return 404 when the module is disabled (treat as "this site does not
 *    advertise UCP" rather than misleading platforms with an empty profile)
 *
 * @see https://ucp.dev/latest/specification/overview/#hosting
 */
class Ucp implements HttpGetActionInterface
{
    private const CACHE_MAX_AGE = 300;

    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly ProfileGeneratorInterface $profileGenerator,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            return $this->resultFactory
                ->create(ResultFactory::TYPE_RAW)
                ->setHttpResponseCode(404)
                ->setHeader('Content-Type', 'application/json', true)
                ->setHeader('Cache-Control', 'no-store', true)
                ->setContents('{"error":"ucp_not_advertised"}');
        }

        try {
            $profile = $this->profileGenerator->generate();
            $body = json_encode(
                $profile,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            $this->logger->error(
                '[Angeo_Ucp] Profile JSON encoding failed: ' . $e->getMessage()
            );
            return $this->resultFactory
                ->create(ResultFactory::TYPE_RAW)
                ->setHttpResponseCode(500)
                ->setHeader('Content-Type', 'application/json', true)
                ->setContents('{"error":"profile_generation_failed"}');
        }

        return $this->resultFactory
            ->create(ResultFactory::TYPE_RAW)
            ->setHttpResponseCode(200)
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader(
                'Cache-Control',
                'public, max-age=' . self::CACHE_MAX_AGE,
                true
            )
            ->setHeader('X-UCP-Version', Config::PROTOCOL_VERSION, true)
            ->setContents($body);
    }
}
