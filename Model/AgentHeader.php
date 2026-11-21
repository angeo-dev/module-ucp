<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model;

use Angeo\Ucp\Model\Signature\StructuredFields;

/**
 * Parses the `UCP-Agent` request header.
 *
 * The header is an RFC 8941 Dictionary. The spec reads the `profile` key,
 * whose value is a quoted string with the agent's profile URL:
 *
 *     UCP-Agent: profile="https://platform.example/.well-known/ucp"
 *
 * A version may ride along. The official conformance suite sends it as a
 * parameter of the profile member (`profile="…"; version="2026-08-25"`);
 * a separate dictionary member (`profile="…", version="…"`) is accepted
 * too.
 *
 * One parser for the whole suite. Before 2.2.2 the signature verifier read
 * the raw header as a URL, so a spec-shaped header never resolved to a
 * profile and every signed request failed.
 *
 * @see https://ucp.dev/2026-08-25/specification/signatures/
 */
class AgentHeader
{
    /**
     * @return array{profile: string, version: string}
     */
    public static function parse(string $header): array
    {
        $out = ['profile' => '', 'version' => ''];

        if (trim($header) === '') {
            return $out;
        }

        $members = [];
        foreach (StructuredFields::parseStringDictionary($header) as $key => $member) {
            $members[strtolower(trim((string) $key))] = $member;
        }

        if (isset($members['profile'])) {
            $out['profile'] = trim($members['profile']['value']);
            $out['version'] = trim((string) ($members['profile']['params']['version'] ?? ''));
        }

        if ($out['version'] === '' && isset($members['version'])) {
            $out['version'] = trim($members['version']['value']);
        }

        return $out;
    }

    /**
     * The profile URL, or '' when the header carries none.
     */
    public static function profile(string $header): string
    {
        return self::parse($header)['profile'];
    }

    private function __construct()
    {
    }
}
