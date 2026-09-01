<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Api;

use Angeo\Ucp\Model\Signature\VerificationResult;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Verifies an inbound RFC 9421 signature on a UCP request.
 *
 * Lives in Angeo_Ucp rather than in an endpoint module because every UCP
 * endpoint module needs the same logic, and because key resolution goes
 * through the profile machinery this module already owns.
 *
 * @api
 */
interface SignatureVerifierInterface
{
    public function verify(HttpRequest $request): VerificationResult;
}
