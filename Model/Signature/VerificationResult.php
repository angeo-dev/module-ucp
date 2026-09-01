<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

/**
 * Outcome of verifying an inbound request.
 *
 * `unsigned` and `failed` are kept apart deliberately. A request carrying
 * no signature is a policy question the endpoint answers from its
 * configured mode; a request carrying a signature that does not verify is
 * never acceptable in any mode.
 */
final class VerificationResult
{
    public const UNSIGNED = 'unsigned';
    public const VERIFIED = 'verified';
    public const FAILED   = 'failed';

    private function __construct(
        public readonly string  $status,
        public readonly ?string $agentProfileUrl = null,
        public readonly ?string $keyId = null,
        public readonly string  $reason = ''
    ) {
    }

    public static function unsigned(): self
    {
        return new self(self::UNSIGNED);
    }

    public static function verified(string $agentProfileUrl, string $keyId): self
    {
        return new self(self::VERIFIED, $agentProfileUrl, $keyId);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, null, null, $reason);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function isUnsigned(): bool
    {
        return $this->status === self::UNSIGNED;
    }
}
