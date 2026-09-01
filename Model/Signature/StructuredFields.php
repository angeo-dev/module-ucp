<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

/**
 * Minimal RFC 8941 structured-field parsing, scoped to what RFC 9421
 * signature verification actually needs:
 *
 *   Signature-Input: sig1=("@method" "@authority");created=1;keyid="k";alg="ed25519"
 *   Signature:       sig1=:BASE64:
 *   Signature-Agent: sig1="https://platform.example";type=jwks_uri
 *
 * This is deliberately not a general RFC 8941 implementation. A full parser
 * would add a dependency and a large surface for a handful of shapes; a
 * narrow one that REJECTS anything it does not fully understand is safer,
 * because in signature verification an unparsed input must fail closed.
 */
final class StructuredFields
{
    /**
     * Parse a dictionary whose members are inner lists with parameters —
     * the shape of Signature-Input.
     *
     * @return array<string, array{components: array<int, array{name: string, params: array<string, string>}>, params: array<string, string>}>
     */
    public static function parseInnerListDictionary(string $value): array
    {
        $out = [];

        foreach (self::splitTopLevel($value, ',') as $member) {
            $member = trim($member);
            if ($member === '') {
                continue;
            }

            $eq = strpos($member, '=');
            if ($eq === false) {
                continue;
            }

            $label = trim(substr($member, 0, $eq));
            $rest  = trim(substr($member, $eq + 1));

            if ($label === '' || !str_starts_with($rest, '(')) {
                continue;
            }

            $close = strpos($rest, ')');
            if ($close === false) {
                continue;
            }

            $inner  = substr($rest, 1, $close - 1);
            $params = self::parseParameters(substr($rest, $close + 1));

            $components = [];
            foreach (self::splitTopLevel($inner, ' ') as $item) {
                $item = trim($item);
                if ($item === '') {
                    continue;
                }

                $semi = strpos($item, ';');
                $name = $semi === false ? $item : substr($item, 0, $semi);
                $componentParams = $semi === false ? [] : self::parseParameters(substr($item, $semi));

                $components[] = [
                    'name'   => strtolower(trim($name, '"')),
                    'params' => $componentParams,
                ];
            }

            $out[$label] = ['components' => $components, 'params' => $params];
        }

        return $out;
    }

    /**
     * Parse a dictionary of byte-sequence members — the shape of Signature.
     *
     * @return array<string, string> label => raw signature bytes
     */
    public static function parseByteSequenceDictionary(string $value): array
    {
        $out = [];

        foreach (self::splitTopLevel($value, ',') as $member) {
            $member = trim($member);
            $eq = strpos($member, '=');
            if ($eq === false) {
                continue;
            }

            $label = trim(substr($member, 0, $eq));
            $raw   = trim(substr($member, $eq + 1));

            // Strip any parameters, then the :…: byte-sequence delimiters.
            $semi = strpos($raw, ';');
            if ($semi !== false) {
                $raw = substr($raw, 0, $semi);
            }
            $raw = trim($raw);

            if ($label === '' || strlen($raw) < 2 || $raw[0] !== ':' || !str_ends_with($raw, ':')) {
                continue;
            }

            $decoded = base64_decode(substr($raw, 1, -1), true);
            if ($decoded === false || $decoded === '') {
                continue;
            }

            $out[$label] = $decoded;
        }

        return $out;
    }

    /**
     * Parse a dictionary of string members with parameters — Signature-Agent.
     *
     * @return array<string, array{value: string, params: array<string, string>}>
     */
    public static function parseStringDictionary(string $value): array
    {
        $out = [];

        foreach (self::splitTopLevel($value, ',') as $member) {
            $member = trim($member);
            $eq = strpos($member, '=');
            if ($eq === false) {
                continue;
            }

            $label = trim(substr($member, 0, $eq));
            $rest  = trim(substr($member, $eq + 1));

            $semi   = strpos($rest, ';');
            $bare   = $semi === false ? $rest : substr($rest, 0, $semi);
            $params = $semi === false ? [] : self::parseParameters(substr($rest, $semi));

            $bare = trim(trim($bare), '"');
            if ($label === '' || $bare === '') {
                continue;
            }

            $out[$label] = ['value' => $bare, 'params' => $params];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function parseParameters(string $raw): array
    {
        $params = [];

        foreach (self::splitTopLevel($raw, ';') as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $eq = strpos($chunk, '=');
            if ($eq === false) {
                // Boolean-true parameter form.
                $params[strtolower($chunk)] = '?1';
                continue;
            }

            $key = strtolower(trim(substr($chunk, 0, $eq)));
            $val = trim(substr($chunk, $eq + 1));

            // Byte sequences and quoted strings both unwrap to their content.
            if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
                $val = substr($val, 1, -1);
            }

            $params[$key] = $val;
        }

        return $params;
    }

    /**
     * Split on a delimiter that is not inside quotes or parentheses.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $value, string $delimiter): array
    {
        $parts    = [];
        $buffer   = '';
        $inQuotes = false;
        $depth    = 0;

        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"' && ($i === 0 || $value[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === '(') {
                $depth++;
            } elseif (!$inQuotes && $char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($char === $delimiter && !$inQuotes && $depth === 0) {
                $parts[] = $buffer;
                $buffer  = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    private function __construct()
    {
    }
}
