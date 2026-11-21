<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * A mocked request plus the headers it answers with, so a test can build a
 * second request from the first one's headers.
 */
class RequestDouble
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly HttpRequest $mock,
        public readonly array $headers
    ) {
    }
}
