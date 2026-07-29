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
use Magento\Framework\App\Action\HttpOptionsActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Psr\Log\LoggerInterface;

/**
 * Serves /.well-known/ucp.
 *
 * Response MUST:
 *  - be served over HTTPS (enforced at web-server level)
 *  - return Content-Type: application/json
 *  - return Cache-Control with public + max-age >= 60 (per UCP spec §hosting)
 *  - NOT use 3xx redirects (handled by web server)
 *  - return 404 when the module is disabled (treat as "this site does not
 *    advertise UCP" rather than misleading platforms with an empty profile)
 *
 * CORS preflight (1.3.0): the action also implements
 * HttpOptionsActionInterface and answers OPTIONS with 204 No Content plus
 * the CORS/Allow headers, so the advertised
 * `Access-Control-Allow-Methods: GET, OPTIONS` is actually honoured.
 * Server-side AI agents never preflight, but a browser-based client with
 * custom request headers would — and previously Magento's HTTP-method
 * validation rejected OPTIONS outright.
 *
 * Security notes (1.0.0):
 *  - Security headers (X-Content-Type-Options, X-Frame-Options, etc.) are
 *    added to all responses to comply with standard hardening practices.
 *  - Rate-limiting is the responsibility of the operator's reverse proxy /
 *    WAF; this endpoint has no authentication and should be fronted with
 *    appropriate rate-limit rules in production.
 *
 * @see https://ucp.dev/2026-04-08/specification/overview/#hosting
 */
class Ucp implements HttpGetActionInterface, HttpOptionsActionInterface
{
    private const CACHE_MAX_AGE = 300;

    /** How long browsers may cache the CORS preflight result (seconds). */
    private const PREFLIGHT_MAX_AGE = 86400;

    public function __construct(
        private readonly ResultFactory             $resultFactory,
        private readonly ProfileGeneratorInterface $profileGenerator,
        private readonly Config                    $config,
        private readonly LoggerInterface           $logger,
        private readonly RequestInterface          $request
    ) {
    }

    public function execute(): ResultInterface
    {
        if ($this->request instanceof HttpRequest
            && strtoupper((string) $this->request->getMethod()) === 'OPTIONS'
        ) {
            return $this->buildPreflightResponse();
        }

        if (!$this->config->isEnabled()) {
            return $this->buildResponse(404, 'no-store', '{"error":"ucp_not_advertised"}');
        }

        try {
            $profile = $this->profileGenerator->generate();
            $body    = json_encode(
                $profile,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            $this->logger->error(
                '[Angeo_Ucp] Profile JSON encoding failed: ' . $e->getMessage()
            );
            return $this->buildResponse(500, 'no-store', '{"error":"profile_generation_failed"}');
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Angeo_Ucp] Profile generation failed: ' . $e->getMessage()
            );
            return $this->buildResponse(500, 'no-store', '{"error":"profile_generation_failed"}');
        }

        return $this->buildResponse(
            200,
            'public, max-age=' . self::CACHE_MAX_AGE,
            $body,
            ['X-UCP-Version' => Config::PROTOCOL_VERSION]
        );
    }

    /**
     * CORS preflight: 204 No Content with Allow/CORS headers.
     *
     * Returned regardless of the enabled flag — the preflight only describes
     * which methods are allowed, it must not leak whether UCP is advertised.
     */
    private function buildPreflightResponse(): ResultInterface
    {
        return $this->resultFactory
            ->create(ResultFactory::TYPE_RAW)
            ->setHttpResponseCode(204)
            ->setHeader('Allow', 'GET, OPTIONS', true)
            ->setHeader('Access-Control-Allow-Origin', '*', true)
            ->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS', true)
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type', true)
            ->setHeader('Access-Control-Max-Age', (string) self::PREFLIGHT_MAX_AGE, true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setContents('');
    }

    /**
     * Build a raw JSON response with consistent security headers.
     *
     * @param array<string, string> $extraHeaders
     */
    private function buildResponse(
        int    $httpCode,
        string $cacheControl,
        string $body,
        array  $extraHeaders = []
    ): ResultInterface {
        $result = $this->resultFactory
            ->create(ResultFactory::TYPE_RAW)
            ->setHttpResponseCode($httpCode)
            ->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Cache-Control', $cacheControl, true)
            // CORS — the UCP profile is fetched cross-origin by AI agents, so the
            // spec requires it to be publicly readable from any origin.
            ->setHeader('Access-Control-Allow-Origin', '*', true)
            ->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS', true)
            // Security hardening headers — added in 1.0.0.
            ->setHeader('X-Content-Type-Options', 'nosniff', true)
            ->setHeader('X-Frame-Options', 'DENY', true)
            ->setHeader('Referrer-Policy', 'no-referrer', true)
            ->setContents($body);

        foreach ($extraHeaders as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result;
    }
}
