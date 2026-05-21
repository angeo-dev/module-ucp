<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads Angeo UCP admin configuration with safe defaults.
 *
 * All values are scoped to the current store view so multi-store setups
 * can advertise different capabilities per storefront.
 */
class Config
{
    public const PROTOCOL_VERSION = '2026-04-08';

    public const XML_PATH_ENABLED = 'angeo_ucp/general/enabled';
    public const XML_PATH_CAP_CATALOG = 'angeo_ucp/capabilities/catalog_enabled';
    public const XML_PATH_CAP_CART = 'angeo_ucp/capabilities/cart_enabled';
    public const XML_PATH_CAP_CHECKOUT = 'angeo_ucp/capabilities/checkout_enabled';
    public const XML_PATH_CAP_ORDER = 'angeo_ucp/capabilities/order_enabled';
    public const XML_PATH_CAP_IDENTITY = 'angeo_ucp/capabilities/identity_linking_enabled';
    public const XML_PATH_REST_ENDPOINT = 'angeo_ucp/transport/rest_endpoint';
    public const XML_PATH_SIGNING_KEY_JWK = 'angeo_ucp/keys/signing_key_jwk';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->getFlag(self::XML_PATH_ENABLED);
    }

    public function isCatalogDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CATALOG);
    }

    public function isCartDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CART);
    }

    public function isCheckoutDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CHECKOUT);
    }

    public function isOrderDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_ORDER);
    }

    public function isIdentityLinkingDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_IDENTITY);
    }

    /**
     * Returns the REST endpoint URL for the dev.ucp.shopping service.
     * Defaults to {baseUrl}/rest/V1/ucp.
     */
    public function getRestEndpoint(): string
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_PATH_REST_ENDPOINT,
            ScopeInterface::SCOPE_STORE
        );

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable) {
            return '';
        }

        return rtrim($baseUrl, '/') . '/rest/V1/ucp';
    }

    /**
     * @return array<int, array<string, string>> Public JWK keys (no private material).
     */
    public function getPublicSigningKeys(): array
    {
        $stored = (string) $this->scopeConfig->getValue(
            self::XML_PATH_SIGNING_KEY_JWK,
            ScopeInterface::SCOPE_STORE
        );

        if ($stored === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        // Strip any private key material (defence-in-depth — keys
        // are stored public-only, but this guards against misconfig).
        $privateFields = ['d', 'p', 'q', 'dp', 'dq', 'qi'];
        $sanitized = [];
        foreach ($decoded as $key) {
            if (!is_array($key)) {
                continue;
            }
            foreach ($privateFields as $field) {
                unset($key[$field]);
            }
            $sanitized[] = $key;
        }

        return $sanitized;
    }

    private function getFlag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }
}
