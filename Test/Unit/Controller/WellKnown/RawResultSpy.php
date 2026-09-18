<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Controller\WellKnown;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Test double for the RAW result: records the response code, headers,
 * and body the controller sets, so assertions can inspect them.
 */
class RawResultSpy implements ResultInterface
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
