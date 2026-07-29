<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Controller\WellKnown;

use Angeo\Ucp\Api\ProfileGeneratorInterface;
use Angeo\Ucp\Controller\WellKnown\Ucp;
use Angeo\Ucp\Model\Config;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test double for the RAW result: records the response code, headers,
 * and body the controller sets, so assertions can inspect them.
 */
final class RawResultSpy implements ResultInterface
{
    public int $httpCode = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public string $contents = '';

    public function setHttpResponseCode($httpCode)
    {
        $this->httpCode = (int) $httpCode;
        return $this;
    }

    public function setHeader($name, $value, $replace = false)
    {
        $this->headers[(string) $name] = (string) $value;
        return $this;
    }

    public function setContents($contents)
    {
        $this->contents = (string) $contents;
        return $this;
    }

    public function renderResult(ResponseInterface $response)
    {
        return $this;
    }
}

#[CoversClass(Ucp::class)]
final class UcpTest extends TestCase
{
    #[Test]
    public function options_request_returns_204_preflight_with_cors_headers(): void
    {
        [$controller, ] = $this->buildController(enabled: true, method: 'OPTIONS');

        /** @var RawResultSpy $result */
        $result = $controller->execute();

        self::assertSame(204, $result->httpCode);
        self::assertSame('', $result->contents);
        self::assertSame('GET, OPTIONS', $result->headers['Allow']);
        self::assertSame('*', $result->headers['Access-Control-Allow-Origin']);
        self::assertSame('GET, OPTIONS', $result->headers['Access-Control-Allow-Methods']);
        self::assertArrayHasKey('Access-Control-Max-Age', $result->headers);
    }

    #[Test]
    public function options_preflight_does_not_leak_enabled_state(): void
    {
        // Preflight must behave identically whether or not UCP is advertised.
        [$controllerOff, ] = $this->buildController(enabled: false, method: 'OPTIONS');

        /** @var RawResultSpy $result */
        $result = $controllerOff->execute();

        self::assertSame(204, $result->httpCode);
    }

    #[Test]
    public function get_returns_404_no_store_when_module_disabled(): void
    {
        [$controller, ] = $this->buildController(enabled: false);

        /** @var RawResultSpy $result */
        $result = $controller->execute();

        self::assertSame(404, $result->httpCode);
        self::assertSame('no-store', $result->headers['Cache-Control']);
        self::assertSame('application/json', $result->headers['Content-Type']);
    }

    #[Test]
    public function get_returns_200_json_with_spec_required_headers(): void
    {
        [$controller, ] = $this->buildController(enabled: true);

        /** @var RawResultSpy $result */
        $result = $controller->execute();

        self::assertSame(200, $result->httpCode);
        self::assertSame('application/json', $result->headers['Content-Type']);
        self::assertSame('*', $result->headers['Access-Control-Allow-Origin']);

        // Spec §hosting: Cache-Control MUST be public with max-age >= 60.
        self::assertMatchesRegularExpression(
            '/^public, max-age=(\d+)$/',
            $result->headers['Cache-Control']
        );
        preg_match('/max-age=(\d+)/', $result->headers['Cache-Control'], $m);
        self::assertGreaterThanOrEqual(60, (int) $m[1]);

        $decoded = json_decode($result->contents, true);
        self::assertSame(Config::PROTOCOL_VERSION, $decoded['ucp']['version']);
    }

    #[Test]
    public function get_returns_500_no_store_when_profile_generation_throws(): void
    {
        [$controller, ] = $this->buildController(
            enabled: true,
            generatorException: new \RuntimeException('boom')
        );

        /** @var RawResultSpy $result */
        $result = $controller->execute();

        self::assertSame(500, $result->httpCode);
        self::assertSame('no-store', $result->headers['Cache-Control']);
        self::assertStringContainsString('profile_generation_failed', $result->contents);
    }

    /**
     * @return array{0: Ucp, 1: RawResultSpy}
     */
    private function buildController(
        bool $enabled,
        string $method = 'GET',
        ?\Throwable $generatorException = null
    ): array {
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            static fn () => new RawResultSpy()
        );

        $generator = $this->createMock(ProfileGeneratorInterface::class);
        if ($generatorException !== null) {
            $generator->method('generate')->willThrowException($generatorException);
        } else {
            $generator->method('generate')->willReturn([
                'ucp' => [
                    'version'          => Config::PROTOCOL_VERSION,
                    'services'         => new \stdClass(),
                    'capabilities'     => new \stdClass(),
                    'payment_handlers' => new \stdClass(),
                ],
            ]);
        }

        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        // Mock rather than instantiate: the real HttpRequest constructor
        // has framework dependencies (cookie reader, string utils, ...).
        $request = $this->createMock(HttpRequest::class);
        $request->method('getMethod')->willReturn($method);

        $controller = new Ucp(
            $resultFactory,
            $generator,
            $config,
            $this->createMock(LoggerInterface::class),
            $request
        );

        return [$controller, new RawResultSpy()];
    }
}
