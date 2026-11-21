<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Network;

/**
 * Resolves a host name to every IPv4 and IPv6 address it has.
 *
 * Its own class so that PublicAddressGuard can be tested without DNS, and
 * so a deployment behind a custom resolver can swap it in di.xml.
 */
class HostResolver
{
    /**
     * @return array<int, string> IP addresses, possibly empty
     */
    public function resolve(string $host): array
    {
        $addresses = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            // Magento's error handler turns the DNS warning into an
            // exception; an unresolvable host simply yields no records.
            try {
                $records = dns_get_record($host, $type) ?: [];
            } catch (\Throwable) {
                $records = [];
            }

            foreach ($records as $record) {
                if (isset($record[$field]) && is_string($record[$field]) && $record[$field] !== '') {
                    $addresses[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
