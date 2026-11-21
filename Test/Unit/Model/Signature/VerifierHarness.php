<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Signature\SignatureVerifier;
use Angeo\Ucp\Model\Signature\VerificationResult;

/**
 * Lets tests pass a RequestDouble where the verifier takes the request.
 */
class VerifierHarness
{
    public function __construct(
        private readonly SignatureVerifier $verifier
    ) {
    }

    public function verify(RequestDouble $request): VerificationResult
    {
        return $this->verifier->verify($request->mock);
    }
}
