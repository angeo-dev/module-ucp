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
use Psr\Log\LoggerInterface;

/**
 * Reads Angeo UCP admin configuration with safe defaults.
 *
 * All values are scoped to the current store view so multi-store setups
 * can advertise different capabilities per storefront.
 *
 * Spec alignment (UCP 2026-04-08, verified against https://ucp.dev/2026-04-08):
 *  - Catalog is two granular capabilities: dev.ucp.shopping.catalog.search
 *    and dev.ucp.shopping.catalog.lookup (NOT a single "catalog" capability).
 *  - Extensions (fulfillment, discount) use the `extends` field and MUST be
 *    pruned when no parent capability is advertised.
 *  - identity_linking MAY carry config.scopes.
 *  - Businesses supporting older protocol versions SHOULD publish
 *    supported_versions (map of version → profile URI).
 *  - payment_handlers MAY be declared in the business profile.
 */
class Config
{
    public const PROTOCOL_VERSION = '2026-04-08';

    public const XML_PATH_ENABLED               = 'angeo_ucp/general/enabled';

    // Root capabilities.
    public const XML_PATH_CAP_CATALOG_SEARCH    = 'angeo_ucp/capabilities/catalog_search_enabled';
    public const XML_PATH_CAP_CATALOG_LOOKUP    = 'angeo_ucp/capabilities/catalog_lookup_enabled';
    public const XML_PATH_CAP_CART              = 'angeo_ucp/capabilities/cart_enabled';
    public const XML_PATH_CAP_CHECKOUT          = 'angeo_ucp/capabilities/checkout_enabled';
    public const XML_PATH_CAP_ORDER             = 'angeo_ucp/capabilities/order_enabled';
    public const XML_PATH_CAP_IDENTITY          = 'angeo_ucp/capabilities/identity_linking_enabled';

    // Legacy path (pre-1.1.0) — a single "catalog" toggle. Honoured for
    // backwards compatibility: when set, it enables BOTH search and lookup.
    public const XML_PATH_CAP_CATALOG_LEGACY    = 'angeo_ucp/capabilities/catalog_enabled';

    // Extensions (extends a parent capability per spec).
    public const XML_PATH_EXT_FULFILLMENT       = 'angeo_ucp/extensions/fulfillment_enabled';
    public const XML_PATH_EXT_DISCOUNT          = 'angeo_ucp/extensions/discount_enabled';

    // Identity-linking OAuth scopes (comma-separated list in admin).
    public const XML_PATH_IDENTITY_SCOPES       = 'angeo_ucp/capabilities/identity_linking_scopes';

    public const XML_PATH_REST_ENDPOINT         = 'angeo_ucp/transport/rest_endpoint';
    public const XML_PATH_SIGNING_KEY_JWK       = 'angeo_ucp/keys/signing_key_jwk';

    // Advanced: optional raw-JSON declarations.
    public const XML_PATH_SUPPORTED_VERSIONS    = 'angeo_ucp/advanced/supported_versions';
    public const XML_PATH_PAYMENT_HANDLERS      = 'angeo_ucp/advanced/payment_handlers';

    /** Maximum JSON depth accepted when decoding stored JSON config values. */
    private const JSON_MAX_DEPTH = 16;

    /** Fields that must never appear in a public JWK. */
    private const PRIVATE_JWK_FIELDS = ['d', 'p', 'q', 'dp', 'dq', 'qi'];

    public function __construct(
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->getFlag(self::XML_PATH_ENABLED);
    }

    // ── Root capabilities ─────────────────────────────────────────────────────

    public function isCatalogSearchDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CATALOG_SEARCH)
            || $this->getFlag(self::XML_PATH_CAP_CATALOG_LEGACY);
    }

    public function isCatalogLookupDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CATALOG_LOOKUP)
            || $this->getFlag(self::XML_PATH_CAP_CATALOG_LEGACY);
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

    // ── Extensions ────────────────────────────────────────────────────────────

    public function isFulfillmentDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_EXT_FULFILLMENT);
    }

    public function isDiscountDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_EXT_DISCOUNT);
    }

    /**
     * OAuth scopes advertised under identity_linking config.scopes.
     * Stored as a comma-separated list in admin; empty list = no config block.
     *
     * @return array<int, string>
     */
    public function getIdentityLinkingScopes(): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_IDENTITY_SCOPES,
            ScopeInterface::SCOPE_STORE
        );

        if (trim($raw) === '') {
            return [];
        }

        $scopes = array_filter(array_map('trim', explode(',', $raw)));

        return array_values(array_unique($scopes));
    }

    // ── Transport ─────────────────────────────────────────────────────────────

    /**
     * Returns the REST endpoint URL for the dev.ucp.shopping service.
     * Defaults to {baseUrl}/rest/V1/ucp.
     *
     * Per spec: endpoint MUST be a valid HTTPS URL and SHOULD NOT have a
     * trailing slash. Non-HTTPS endpoints are logged as warnings.
     */
    public function getRestEndpoint(): string
    {
        $configured = (string) $this->scopeConfig->getValue(
            self::XML_PATH_REST_ENDPOINT,
            ScopeInterface::SCOPE_STORE
        );

        if ($configured !== '') {
            $endpoint = rtrim($configured, '/');
            $this->warnIfNotHttps($endpoint, 'configured REST endpoint');
            return $endpoint;
        }

        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Angeo_Ucp] Failed to resolve store base URL for UCP endpoint: '
                . $e->getMessage()
            );
            return '';
        }

        $endpoint = rtrim($baseUrl, '/') . '/rest/V1/ucp';
        $this->warnIfNotHttps($endpoint, 'derived REST endpoint');
        return $endpoint;
    }

    // ── Advanced declarations ────────────────────────────────────────────────

    /**
     * Optional supported_versions map: {"YYYY-MM-DD": "https://.../profile"}.
     * Per spec, businesses supporting older protocol versions SHOULD publish
     * version-specific profiles via this field.
     *
     * @return array<string, string>
     */
    public function getSupportedVersions(): array
    {
        $decoded = $this->decodeJsonConfig(self::XML_PATH_SUPPORTED_VERSIONS, 'supported_versions');
        if (!is_array($decoded)) {
            return [];
        }

        $valid = [];
        foreach ($decoded as $version => $uri) {
            if (!is_string($version) || !is_string($uri)) {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $version)) {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] supported_versions key "%s" is not a YYYY-MM-DD '
                    . 'version string; skipping.',
                    $version
                ));
                continue;
            }
            if (!str_starts_with(strtolower($uri), 'https://')) {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] supported_versions URI for "%s" is not HTTPS; skipping.',
                    $version
                ));
                continue;
            }
            $valid[$version] = $uri;
        }

        return $valid;
    }

    /**
     * Optional payment_handlers declaration (raw JSON object per UCP spec).
     * Admin-supplied; validated to be a JSON object before inclusion.
     *
     * @return array<string, mixed>
     */
    public function getPaymentHandlers(): array
    {
        $decoded = $this->decodeJsonConfig(self::XML_PATH_PAYMENT_HANDLERS, 'payment_handlers');

        if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            if (is_array($decoded) && $decoded !== [] && array_is_list($decoded)) {
                $this->logger->warning(
                    '[Angeo_Ucp] payment_handlers must be a JSON object keyed by '
                    . 'handler name (reverse-domain), not a JSON array; ignoring.'
                );
            }
            return [];
        }

        return $decoded;
    }

    // ── Signing keys ──────────────────────────────────────────────────────────

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
            $decoded = json_decode($stored, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(
                '[Angeo_Ucp] Stored UCP signing JWK is not valid JSON; '
                . 'serving profile without signing_keys. Error: ' . $e->getMessage()
            );
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $sanitized          = [];
        $hadPrivateMaterial = false;

        foreach ($decoded as $key) {
            if (!is_array($key)) {
                continue;
            }

            foreach (self::PRIVATE_JWK_FIELDS as $field) {
                if (array_key_exists($field, $key)) {
                    $hadPrivateMaterial = true;
                    unset($key[$field]);
                }
            }

            if ($this->isValidPublicJwk($key)) {
                $sanitized[] = $key;
            } else {
                $this->logger->warning(
                    '[Angeo_Ucp] Stored JWK entry is missing required fields '
                    . '(kid/kty/crv/x/y); skipping. Run angeo:ucp:keys:generate to regenerate.'
                );
            }
        }

        if ($hadPrivateMaterial) {
            $this->logger->warning(
                '[Angeo_Ucp] Stored UCP signing key contained private fields '
                . '(d/p/q/dp/dq/qi). These were stripped before serving the '
                . 'profile, but you should rotate the affected key immediately: '
                . 'bin/magento angeo:ucp:keys:generate --force.'
            );
        }

        return $sanitized;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getFlag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Decode a JSON config value, logging on failure and returning null.
     */
    private function decodeJsonConfig(string $path, string $label): mixed
    {
        $stored = (string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);

        if (trim($stored) === '') {
            return null;
        }

        try {
            return json_decode($stored, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning(sprintf(
                '[Angeo_Ucp] Stored %s config is not valid JSON; ignoring. Error: %s',
                $label,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Validate that a JWK array contains the minimum required public fields.
     *
     * @param array<string, mixed> $key
     */
    private function isValidPublicJwk(array $key): bool
    {
        foreach (['kid', 'kty', 'crv', 'x', 'y'] as $required) {
            if (!isset($key[$required]) || !is_string($key[$required]) || $key[$required] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Log a warning if the given URL does not use HTTPS.
     * The UCP spec requires HTTPS for /.well-known/ucp and all endpoints.
     */
    private function warnIfNotHttps(string $url, string $context): void
    {
        if ($url !== '' && !str_starts_with(strtolower($url), 'https://')) {
            $this->logger->warning(sprintf(
                '[Angeo_Ucp] UCP %s is not HTTPS: "%s". '
                . 'The UCP spec requires HTTPS. AI agents may reject this profile.',
                $context,
                $url
            ));
        }
    }
}
