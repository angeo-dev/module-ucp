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
    /**
     * UCP protocol version advertised by this module.
     *
     * 2.0.0 adopts the 2026-08-25 release. Consequences that ripple through
     * this class: the documentation tree moved under /specification/shopping/
     * (see Model\\Spec), `schema` became REQUIRED on business capabilities,
     * the reverse-domain pattern was widened, and signing keys moved from
     * `signing_keys` to the canonical top-level `keys[]` JWK Set.
     */
    public const PROTOCOL_VERSION = '2026-08-25';

    /** Protocol version shipped by 1.x, kept for the migration notice. */
    public const PREVIOUS_PROTOCOL_VERSION = '2026-04-08';

    /** Module release, sent in the outbound User-Agent. */
    public const MODULE_VERSION = '2.2.2';

    public const XML_PATH_ENABLED               = 'angeo_ucp/general/enabled';

    // Root capabilities.
    public const XML_PATH_CAP_CATALOG_SEARCH    = 'angeo_ucp/capabilities/catalog_search_enabled';
    public const XML_PATH_CAP_CATALOG_LOOKUP    = 'angeo_ucp/capabilities/catalog_lookup_enabled';
    public const XML_PATH_CAP_CART              = 'angeo_ucp/capabilities/cart_enabled';
    public const XML_PATH_CAP_CHECKOUT          = 'angeo_ucp/capabilities/checkout_enabled';
    public const XML_PATH_CAP_ORDER             = 'angeo_ucp/capabilities/order_enabled';
    public const XML_PATH_CAP_IDENTITY          = 'angeo_ucp/capabilities/identity_linking_enabled';
    public const XML_PATH_CAP_PERMALINK         = 'angeo_ucp/capabilities/permalink_enabled';
    public const XML_PATH_PERMALINK_ENDPOINT    = 'angeo_ucp/capabilities/permalink_endpoint';

    // Legacy path (pre-1.1.0) — a single "catalog" toggle. Honoured for
    // backwards compatibility: when set, it enables BOTH search and lookup.
    public const XML_PATH_CAP_CATALOG_LEGACY    = 'angeo_ucp/capabilities/catalog_enabled';

    // Extensions (extends a parent capability per spec).
    public const XML_PATH_EXT_FULFILLMENT       = 'angeo_ucp/extensions/fulfillment_enabled';
    public const XML_PATH_EXT_DISCOUNT          = 'angeo_ucp/extensions/discount_enabled';
    public const XML_PATH_EXT_BUYER_CONSENT     = 'angeo_ucp/extensions/buyer_consent_enabled';
    public const XML_PATH_INBOUND_SIGNATURE_MODE = 'angeo_ucp/security/inbound_signature_mode';
    public const XML_PATH_CHECK_AUTHORITY        = 'angeo_ucp/security/check_authority';

    // Identity-linking OAuth scopes (comma-separated list in admin).
    public const XML_PATH_IDENTITY_SCOPES       = 'angeo_ucp/capabilities/identity_linking_scopes';

    /** Path the Angeo UCP REST modules serve, relative to the store base URL. */
    public const DEFAULT_REST_PATH = '/ucp/v1';

    public const XML_PATH_REST_ENDPOINT         = 'angeo_ucp/transport/rest_endpoint';
    public const XML_PATH_MCP_ENDPOINT          = 'angeo_ucp/transport/mcp_endpoint';
    public const XML_PATH_SIGNING_KEY_JWK       = 'angeo_ucp/keys/signing_key_jwk';

    // Advanced: optional raw-JSON declarations.
    public const XML_PATH_SUPPORTED_VERSIONS    = 'angeo_ucp/advanced/supported_versions';
    public const XML_PATH_PAYMENT_HANDLERS      = 'angeo_ucp/advanced/payment_handlers';

    /** Maximum JSON depth accepted when decoding stored JSON config values. */
    private const JSON_MAX_DEPTH = 16;

    /**
     * Reverse-domain name pattern, copied verbatim from the official
     * schema at spec tag v2026-08-25
     * (source/schemas/common/types/reverse_domain_name.json).
     *
     * WIDENED in 2.0.0. The 2026-04-08 pattern rejected names the current
     * spec explicitly lists as valid: interior hyphens in any segment
     * ("com.example-shop.checkout"), leading digits after the first segment
     * ("com.2example.cart"), and punycode TLDs ("xn--p1ai.example.checkout").
     * A payment handler or third-party binding using one of those was
     * silently dropped from the profile under 1.x.
     */
    public const REVERSE_DOMAIN_PATTERN =
        '/^[a-z](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9_-]*[a-z0-9_])?)+$/';

    /** Reverse-domain name of the UCP shopping service. */
    public const SHOPPING_SERVICE_NAME = 'dev.ucp.shopping';

    /** Fields that must never appear in a public JWK. */
    private const PRIVATE_JWK_FIELDS = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];

    /**
     * Capabilities that need server-side endpoints this module does not ship.
     * They are advertised only when an installed module registers an
     * implementation (see $implementations), whatever the admin toggle says.
     * Catalog and permalink are not gated: Angeo_UcpCatalog and a plain
     * storefront already serve them.
     */
    public const GATED_CAPABILITIES = [
        'dev.ucp.shopping.cart',
        'dev.ucp.shopping.checkout',
        'dev.ucp.shopping.order',
        'dev.ucp.shopping.fulfillment',
        'dev.ucp.shopping.discount',
        'dev.ucp.shopping.buyer_consent',
    ];

    /**
     * @param array<string, bool|string|int> $implementations capability name =>
     *        true, filled through di.xml by the module that actually serves the
     *        capability (for example angeo/module-ucp-checkout registers
     *        dev.ucp.shopping.checkout). Added in 2.2.0.
     */
    public function __construct(
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
        private readonly array                 $implementations = []
    ) {
    }

    /**
     * Whether an installed module serves this capability.
     *
     * Ungated capabilities always count as implemented.
     */
    public function isImplemented(string $capability): bool
    {
        if (!in_array($capability, self::GATED_CAPABILITIES, true)) {
            return true;
        }
        return (bool) ($this->implementations[$capability] ?? false);
    }

    /**
     * Gated capabilities the admin switched on but no module serves. Used by
     * angeo:ucp:validate to explain why a toggle has no effect.
     *
     * @return array<int, string>
     */
    public function getUnimplementedToggles(): array
    {
        $toggles = [
            'dev.ucp.shopping.cart'          => self::XML_PATH_CAP_CART,
            'dev.ucp.shopping.checkout'      => self::XML_PATH_CAP_CHECKOUT,
            'dev.ucp.shopping.order'         => self::XML_PATH_CAP_ORDER,
            'dev.ucp.shopping.fulfillment'   => self::XML_PATH_EXT_FULFILLMENT,
            'dev.ucp.shopping.discount'      => self::XML_PATH_EXT_DISCOUNT,
            'dev.ucp.shopping.buyer_consent' => self::XML_PATH_EXT_BUYER_CONSENT,
        ];

        $missing = [];
        foreach ($toggles as $capability => $path) {
            if ($this->getFlag($path) && !$this->isImplemented($capability)) {
                $missing[] = $capability;
            }
        }
        return $missing;
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
        return $this->getFlag(self::XML_PATH_CAP_CART)
            && $this->isImplemented('dev.ucp.shopping.cart');
    }

    public function isCheckoutDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_CHECKOUT)
            && $this->isImplemented('dev.ucp.shopping.checkout');
    }

    public function isOrderDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_ORDER)
            && $this->isImplemented('dev.ucp.shopping.order');
    }

    public function isIdentityLinkingDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_IDENTITY);
    }

    /**
     * dev.ucp.shopping.permalink — new root capability in 2026-08-25.
     *
     * Handing an agent a buyable URL is the one capability a plain Magento
     * storefront already satisfies without any UCP endpoint, so unlike the
     * other toggles it is safe to advertise on a stock install.
     */
    public function isPermalinkDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_CAP_PERMALINK);
    }

    /**
     * Permalink `config.endpoint` — the base URL an agent appends its
     * permalink payload to. Falls back to {baseUrl}/checkout/cart, which is
     * where Magento's own add-to-cart links resolve.
     */
    public function getPermalinkEndpoint(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_PERMALINK_ENDPOINT,
            ScopeInterface::SCOPE_STORE
        ));

        if ($configured !== '') {
            $endpoint = rtrim($configured, '/');
            $this->warnIfNotHttps($endpoint, 'configured permalink endpoint');
            return $endpoint;
        }

        try {
            $baseUrl = $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable $e) {
            $this->logger->error(
                '[Angeo_Ucp] Failed to resolve store base URL for the permalink '
                . 'endpoint: ' . $e->getMessage()
            );
            return '';
        }

        $endpoint = rtrim($baseUrl, '/') . '/checkout/cart';
        $this->warnIfNotHttps($endpoint, 'derived permalink endpoint');
        return $endpoint;
    }

    // ── Extensions ────────────────────────────────────────────────────────────

    public function isFulfillmentDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_EXT_FULFILLMENT)
            && $this->isImplemented('dev.ucp.shopping.fulfillment');
    }

    public function isDiscountDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_EXT_DISCOUNT)
            && $this->isImplemented('dev.ucp.shopping.discount');
    }

    /**
     * dev.ucp.shopping.buyer_consent — new extension in 2026-08-25.
     * Extends cart and/or checkout; pruned when neither parent is declared.
     */
    public function isBuyerConsentDeclared(): bool
    {
        return $this->getFlag(self::XML_PATH_EXT_BUYER_CONSENT)
            && $this->isImplemented('dev.ucp.shopping.buyer_consent');
    }

    /** Verification is off entirely; endpoints accept unsigned requests. */
    public const SIGNATURE_MODE_DISABLED = 'disabled';

    /** Verify when a signature is present; reject only an invalid one. */
    public const SIGNATURE_MODE_OPTIONAL = 'optional';

    /** Reject unsigned requests as well as invalid ones. */
    public const SIGNATURE_MODE_REQUIRED = 'required';

    /**
     * How UCP endpoint modules treat inbound RFC 9421 signatures.
     *
     * `optional` is the migration mode: a counterparty that signs is
     * authenticated, one that does not is still served. It exists because
     * flipping straight to `required` cuts off every agent that has not
     * implemented signing yet, and a merchant has no way to know in advance
     * which ones those are.
     *
     * An unrecognised stored value falls back to `disabled` rather than to
     * `required` — a typo in configuration should not take a store's UCP
     * endpoints offline.
     */
    public function getInboundSignatureMode(): string
    {
        $mode = strtolower(trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_INBOUND_SIGNATURE_MODE,
            ScopeInterface::SCOPE_STORE
        )));

        return in_array($mode, [
            self::SIGNATURE_MODE_DISABLED,
            self::SIGNATURE_MODE_OPTIONAL,
            self::SIGNATURE_MODE_REQUIRED,
        ], true) ? $mode : self::SIGNATURE_MODE_DISABLED;
    }

    /**
     * Whether a verified signature's `@authority` must be one of this
     * Magento's store hosts (2.2.2, on by default).
     *
     * `@authority` is covered so a signature cannot be relayed to another
     * host. The spec only requires it to be covered; checking it against
     * our own hosts is what stops a request signed for shop A from being
     * accepted by shop B when B's web server answers any Host header.
     */
    public function isAuthorityCheckEnabled(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_CHECK_AUTHORITY, ScopeInterface::SCOPE_STORE);

        // Unset (older config.xml cache) counts as on.
        return $value === null || $value === '' || (bool) (int) $value;
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
     * Defaults to {baseUrl}/ucp/v1 (2.2.0; was {baseUrl}/rest/V1/ucp).
     *
     * Per spec: endpoint MUST be a valid HTTPS URL and SHOULD NOT have a
     * trailing slash. Non-HTTPS endpoints are logged as warnings.
     */
    public function getRestEndpoint(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_REST_ENDPOINT,
            ScopeInterface::SCOPE_STORE
        ));

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

        // 2.2.0: was {baseUrl}/rest/V1/ucp, a path nothing serves — Magento's
        // Web API has no such route, and Angeo_UcpCatalog and
        // Angeo_UcpCheckout both answer under /ucp/v1. An agent that followed
        // the default profile got 404 on every call.
        $endpoint = rtrim($baseUrl, '/') . self::DEFAULT_REST_PATH;
        $this->warnIfNotHttps($endpoint, 'derived REST endpoint');
        return $endpoint;
    }

    /**
     * Optional MCP transport endpoint (e.g. the Streamable HTTP endpoint of
     * angeo/module-mcp-server). Advertised in the profile as an `mcp`
     * binding of dev.ucp.shopping ONLY when explicitly configured — there
     * is deliberately no derived fallback, because an MCP server is
     * separate, opt-in infrastructure.
     */
    public function getMcpEndpoint(): string
    {
        $configured = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_MCP_ENDPOINT,
            ScopeInterface::SCOPE_STORE
        ));

        if ($configured === '') {
            return '';
        }

        $endpoint = rtrim($configured, '/');
        $this->warnIfNotHttps($endpoint, 'configured MCP endpoint');
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
     *
     * Validated against the official schema (payment_handler.json, spec repo
     * tag v2026-04-08): the registry is a JSON object keyed by reverse-domain
     * handler name; each value is an ARRAY of handler entries; each entry
     * MUST carry `id` (string) and `version` (YYYY-MM-DD). Invalid entries
     * are skipped with a logged warning so the served profile always
     * validates.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getPaymentHandlers(): array
    {
        $decoded = $this->decodeJsonConfig(self::XML_PATH_PAYMENT_HANDLERS, 'payment_handlers');

        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            $this->logger->warning(
                '[Angeo_Ucp] payment_handlers must be a JSON object keyed by '
                . 'handler name (reverse-domain), not a JSON array; ignoring.'
            );
            return [];
        }

        $valid = [];

        foreach ($decoded as $handlerName => $entries) {
            if (!is_string($handlerName)
                || !preg_match(self::REVERSE_DOMAIN_PATTERN, $handlerName)
            ) {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] payment_handlers key "%s" is not a valid '
                    . 'reverse-domain name (e.g. "com.example.pay"); skipping.',
                    is_string($handlerName) ? $handlerName : gettype($handlerName)
                ));
                continue;
            }

            // Tolerate a single entry object by wrapping it into a list.
            if (is_array($entries) && !array_is_list($entries)) {
                $entries = [$entries];
            }

            if (!is_array($entries)) {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] payment_handlers["%s"] must be an array of '
                    . 'handler entries; skipping.',
                    $handlerName
                ));
                continue;
            }

            $validEntries = [];
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                // Schema: entity requires `version`; handler base requires `id`.
                $id      = $entry['id'] ?? null;
                $version = $entry['version'] ?? null;
                if (!is_string($id) || $id === ''
                    || !is_string($version)
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $version)
                ) {
                    $this->logger->warning(sprintf(
                        '[Angeo_Ucp] payment_handlers["%s"] entry is missing a '
                        . 'string "id" and/or a YYYY-MM-DD "version" (both '
                        . 'REQUIRED per spec); skipping the entry.',
                        $handlerName
                    ));
                    continue;
                }
                $validEntries[] = $entry;
            }

            if ($validEntries !== []) {
                $valid[$handlerName] = $validEntries;
            }
        }

        return $valid;
    }

    // ── Signing keys ──────────────────────────────────────────────────────────

    /**
     * Public JWKs for the profile's top-level `keys[]` array (RFC 7517 JWK Set).
     *
     * Renamed from getPublicSigningKeys() in 2.0.0 to match the spec field.
     * Validation now follows profile.json#/$defs/jwk_public_key at tag
     * v2026-08-25, which is materially different from 1.x:
     *
     *  - only `kid` and `kty` are universally REQUIRED;
     *  - EC keys additionally require crv/x/y, OKP keys require crv/x —
     *    so an Ed25519 key (no `y`) is valid and 1.x wrongly discarded it;
     *  - `alg` MUST match `crv` for the well-known curves (ES256/P-256,
     *    ES384/P-384, EdDSA/Ed25519); a mismatch fails schema validation;
     *  - the kty/crv/alg vocabularies are OPEN — an unrecognised key type
     *    MUST NOT cause whole-profile rejection, so unknown types are
     *    published as-is rather than dropped.
     *
     * @return array<int, array<string, string>> Public JWKs (no private material).
     */
    public function getPublicKeys(): array
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

            $problem = $this->describeInvalidJwk($key);
            if ($problem === '') {
                $sanitized[] = $key;
            } else {
                $this->logger->warning(sprintf(
                    '[Angeo_Ucp] Stored JWK entry is not a valid public key (%s); '
                    . 'skipping. Run angeo:ucp:keys:generate to regenerate.',
                    $problem
                ));
            }
        }

        if ($hadPrivateMaterial) {
            $this->logger->warning(
                '[Angeo_Ucp] Stored UCP signing key contained private fields '
                . '(d/p/q/dp/dq/qi/oth/k). These were stripped before serving the '
                . 'profile, but you should rotate the affected key immediately: '
                . 'bin/magento angeo:ucp:keys:generate --force.'
            );
        }

        return $sanitized;
    }

    /**
     * @deprecated 2.0.0 Use getPublicKeys(). The profile field is `keys`,
     *             not `signing_keys`, as of protocol version 2026-08-25.
     * @return array<int, array<string, string>>
     */
    public function getPublicSigningKeys(): array
    {
        return $this->getPublicKeys();
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
     * Required members per key type (profile.json#/$defs/jwk_public_key).
     * Absent from this map = open vocabulary: kid + kty only.
     */
    private const JWK_REQUIRED_BY_KTY = [
        'EC'  => ['crv', 'x', 'y'],
        'OKP' => ['crv', 'x'],
    ];

    /** Curve -> the only `alg` the schema permits alongside it. */
    private const JWK_ALG_BY_CRV = [
        'P-256'   => 'ES256',
        'P-384'   => 'ES384',
        'Ed25519' => 'EdDSA',
    ];

    /**
     * Validate a public JWK against profile.json#/$defs/jwk_public_key.
     *
     * @param array<string, mixed> $key
     * @return string Empty string when valid; otherwise the reason to log.
     */
    private function describeInvalidJwk(array $key): string
    {
        foreach (['kid', 'kty'] as $required) {
            if (!isset($key[$required]) || !is_string($key[$required]) || $key[$required] === '') {
                return sprintf('missing required member "%s"', $required);
            }
        }

        $kty = (string) $key['kty'];

        foreach (self::JWK_REQUIRED_BY_KTY[$kty] ?? [] as $required) {
            if (!isset($key[$required]) || !is_string($key[$required]) || $key[$required] === '') {
                return sprintf('%s key is missing required member "%s"', $kty, $required);
            }
        }

        $crv = isset($key['crv']) && is_string($key['crv']) ? $key['crv'] : null;
        $alg = isset($key['alg']) && is_string($key['alg']) ? $key['alg'] : null;

        if ($crv !== null && $alg !== null
            && isset(self::JWK_ALG_BY_CRV[$crv])
            && self::JWK_ALG_BY_CRV[$crv] !== $alg
        ) {
            return sprintf(
                'alg "%s" contradicts crv "%s" (the schema pins %s)',
                $alg,
                $crv,
                self::JWK_ALG_BY_CRV[$crv]
            );
        }

        return '';
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
