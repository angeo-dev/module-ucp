<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Angeo\Ucp\Model\Config;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Is the signed `@authority` one of this Magento's own hosts?
 *
 * A signature covers `@authority` so it cannot be relayed to another host.
 * That only works if the verifier compares the covered value with the host
 * it actually is: when a web server answers any Host header, a request
 * signed for shop A and sent to shop B with `Host: shop-a` would otherwise
 * verify at B. New in 2.2.2; switch off with
 * angeo_ucp/security/check_authority.
 */
class AuthorityPolicy
{
    /** @var array<int, string>|null */
    private ?array $own = null;

    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function isAllowed(string $authority): bool
    {
        if (!$this->config->isAuthorityCheckEnabled()) {
            return true;
        }

        $own = $this->ownAuthorities();

        // No store hosts could be read: do not lock every agent out.
        if ($own === []) {
            return true;
        }

        return in_array(strtolower(trim($authority)), $own, true);
    }

    /**
     * Authorities (host, plus port when not the default) of every store's
     * secure and unsecure base URL.
     *
     * @return array<int, string>
     */
    public function ownAuthorities(): array
    {
        if ($this->own !== null) {
            return $this->own;
        }

        $own = [];

        try {
            foreach ($this->storeManager->getStores(true) as $store) {
                foreach ([true, false] as $secure) {
                    $authority = self::authorityOf(
                        (string) $store->getBaseUrl(UrlInterface::URL_TYPE_WEB, $secure)
                    );
                    if ($authority !== '') {
                        $own[] = $authority;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Angeo_Ucp] Could not read store hosts: ' . $e->getMessage());
        }

        return $this->own = array_values(array_unique($own));
    }

    /**
     * RFC 9421 @authority form of a URL: lowercase host, port only when it
     * is not the scheme default.
     */
    public static function authorityOf(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host   = strtolower((string) $parts['host']);
        $port   = isset($parts['port']) ? (int) $parts['port'] : null;

        $default = $scheme === 'http' ? 80 : 443;

        return $port !== null && $port !== $default ? $host . ':' . $port : $host;
    }
}
