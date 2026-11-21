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
class VerificationResult
{
    public const UNSIGNED = 'unsigned';
    public const VERIFIED = 'verified';
    public const FAILED   = 'failed';

    /*
     * Error codes from the UCP signatures spec (2026-08-25, "Error Codes").
     * Added in 2.2.2 so endpoints can answer with the code the spec names.
     */
    public const CODE_SIGNATURE_MISSING     = 'signature_missing';     // 401
    public const CODE_SIGNATURE_INVALID     = 'signature_invalid';     // 401
    public const CODE_KEY_NOT_FOUND         = 'key_not_found';         // 401
    public const CODE_DIGEST_MISMATCH       = 'digest_mismatch';       // 400
    public const CODE_ALGORITHM_UNSUPPORTED = 'algorithm_unsupported'; // 400

    private function __construct(
        public readonly string  $status,
        public readonly ?string $agentProfileUrl = null,
        public readonly ?string $keyId = null,
        public readonly string  $reason = '',
        public readonly string  $code = ''
    ) {
    }

    public static function unsigned(): self
    {
        return new self(self::UNSIGNED, null, null, '', self::CODE_SIGNATURE_MISSING);
    }

    public static function verified(string $agentProfileUrl, string $keyId): self
    {
        return new self(self::VERIFIED, $agentProfileUrl, $keyId);
    }

    public static function failed(string $reason, string $code = self::CODE_SIGNATURE_INVALID): self
    {
        return new self(self::FAILED, null, null, $reason, $code);
    }

    public function isVerified(): bool
    {
        return $this->status === self::VERIFIED;
    }

    public function isUnsigned(): bool
    {
        return $this->status === self::UNSIGNED;
    }

    /**
     * HTTP status the spec gives the error code: 400 for a digest mismatch
     * or an unsupported algorithm, 401 for everything else.
     */
    public function httpStatus(): int
    {
        return in_array($this->code, [self::CODE_DIGEST_MISMATCH, self::CODE_ALGORITHM_UNSUPPORTED], true)
            ? 400
            : 401;
    }
}
