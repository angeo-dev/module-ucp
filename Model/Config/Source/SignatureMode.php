<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Config\Source;

use Angeo\Ucp\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Admin options for the inbound signature policy.
 */
class SignatureMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::SIGNATURE_MODE_DISABLED,
                'label' => __('Disabled — accept every request unverified'),
            ],
            [
                'value' => Config::SIGNATURE_MODE_OPTIONAL,
                'label' => __('Optional — verify when present, reject invalid'),
            ],
            [
                'value' => Config::SIGNATURE_MODE_REQUIRED,
                'label' => __('Required — reject unsigned and invalid requests'),
            ],
        ];
    }
}
