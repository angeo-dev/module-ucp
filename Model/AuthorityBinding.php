<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

/**
 * Implements the UCP 2026-08-25 authority-binding derivation algorithm
 * (spec: Discovery -> Namespace Governance -> Authority Binding).
 *
 * A platform MUST validate every business-declared `schema` URL before
 * fetching it, and MUST reject the entity when the binding fails — the
 * capability is then treated as not present and never activated. Running
 * the same check locally (via `angeo:ucp:validate`) catches a profile that
 * platforms would silently strip.
 *
 * Algorithm, verbatim from the spec:
 *  1. Parse with a conformant URL parser; MUST be https, MUST NOT carry
 *     userinfo. Substring matching on the raw URL is NOT permitted —
 *     https://ucp.dev@evil.example/x.json has host evil.example.
 *  2. Host MUST be a registered domain of at least two labels; IP literals
 *     and single-label hosts are invalid authorities.
 *  3. Lowercase the hostname, strip a trailing dot, and REVERSE its labels
 *     to form the authority_prefix (ucp.dev -> dev.ucp).
 *  4. Valid iff the entity name equals authority_prefix (exact) OR begins
 *     with authority_prefix followed by a '.' (label-aligned prefix).
 */
final class AuthorityBinding
{
    /**
     * @return array{0: bool, 1: string} [valid, human-readable reason]
     */
    public static function check(string $entityName, string $schemaUrl): array
    {
        $parts = parse_url($schemaUrl);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return [false, sprintf('schema URL "%s" is not parseable.', $schemaUrl)];
        }

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return [false, sprintf('schema URL "%s" must use https.', $schemaUrl)];
        }

        // Step 1: userinfo makes the apparent host a decoy.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return [false, sprintf(
                'schema URL "%s" contains userinfo; the real host is "%s".',
                $schemaUrl,
                $parts['host']
            )];
        }

        $host = rtrim(strtolower($parts['host']), '.');

        // Step 2: IP literals and single-label hosts are not authorities.
        if ($host === ''
            || str_contains($host, ':')                 // IPv6 literal
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || !str_contains($host, '.')
        ) {
            return [false, sprintf(
                'schema host "%s" is not a registered domain of at least two labels.',
                $host
            )];
        }

        // Step 3: reverse the labels.
        $authorityPrefix = implode('.', array_reverse(explode('.', $host)));

        // Step 4: exact match or label-aligned prefix.
        if ($entityName === $authorityPrefix
            || str_starts_with($entityName, $authorityPrefix . '.')
        ) {
            return [true, ''];
        }

        return [false, sprintf(
            'authority binding failed: "%s" is served from host "%s" '
            . '(authority prefix "%s"), which is neither the name nor a '
            . 'label-aligned prefix of it. Platforms MUST reject this entity.',
            $entityName,
            $host,
            $authorityPrefix
        )];
    }

    private function __construct()
    {
    }
}
