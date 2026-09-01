<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Model\Signature;

use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Reconstructs the RFC 9421 signature base for an inbound HTTP request.
 *
 * The base is the exact byte string the signer signed. Each covered
 * component contributes one line `"name": value\n`, in the order the
 * signer listed them, and the final line is `"@signature-params": <params>`
 * with NO trailing newline. Any deviation — reordering, a different
 * whitespace normalisation, a missing derived component — produces a
 * different string and the signature simply fails.
 *
 * Supported derived components: @method, @authority, @path, @query,
 * @target-uri, @scheme. Unsupported ones cause reconstruction to fail
 * rather than silently omitting a line, because an omitted line is an
 * unverified component.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9421#name-creating-the-signature-base
 */
class SignatureBase
{
    /**
     * @param array<int, array{name: string, params: array<string, string>}> $components
     * @param string $rawSignatureParams The `("…");created=…` text exactly as
     *        it appeared in Signature-Input — it MUST be echoed verbatim,
     *        not re-serialised, or the base will not match.
     * @return string|null Null when a component cannot be reconstructed.
     */
    public function build(
        HttpRequest $request,
        array $components,
        string $rawSignatureParams
    ): ?string {
        $lines = [];

        foreach ($components as $component) {
            $name  = $component['name'];
            $value = $this->componentValue($request, $name, $component['params']);

            if ($value === null) {
                return null;
            }

            $lines[] = $this->serializeName($name, $component['params']) . ': ' . $value;
        }

        $lines[] = '"@signature-params": ' . $rawSignatureParams;

        return implode("\n", $lines);
    }

    /**
     * Component identifiers are quoted, with their parameters appended —
     * `"signature-agent";key="sig1"`.
     *
     * @param array<string, string> $params
     */
    private function serializeName(string $name, array $params): string
    {
        $serialized = '"' . $name . '"';

        foreach ($params as $key => $value) {
            $serialized .= $value === '?1'
                ? ';' . $key
                : ';' . $key . '="' . $value . '"';
        }

        return $serialized;
    }

    /**
     * @param array<string, string> $params
     */
    private function componentValue(HttpRequest $request, string $name, array $params): ?string
    {
        if (str_starts_with($name, '@')) {
            return $this->derivedValue($request, $name);
        }

        $header = $request->getHeader($name);
        if ($header === false || $header === null) {
            return null;
        }

        $value = trim((string) $header);

        // Dictionary-member selection, RFC 9421 §2.1.2. Required to cover
        // `signature-agent;key="sig1"` in dual-audience signatures.
        if (isset($params['key'])) {
            return $this->dictionaryMember($value, $params['key']);
        }

        return $value;
    }

    private function derivedValue(HttpRequest $request, string $name): ?string
    {
        $uri   = (string) $request->getRequestUri();
        $path  = (string) parse_url($uri, PHP_URL_PATH);
        $query = (string) parse_url($uri, PHP_URL_QUERY);

        return match ($name) {
            '@method'    => strtoupper((string) $request->getMethod()),
            '@authority' => $this->authority($request),
            '@path'      => $path !== '' ? $path : '/',
            // RFC 9421: @query is the leading '?' plus the query string, and
            // an empty query is the single character '?'.
            '@query'      => '?' . $query,
            '@scheme'     => $request->isSecure() ? 'https' : 'http',
            '@target-uri' => ($request->isSecure() ? 'https://' : 'http://')
                . $this->authority($request) . $uri,
            default => null,
        };
    }

    /**
     * Host in lowercase, with the port omitted when it is the scheme default.
     */
    private function authority(HttpRequest $request): string
    {
        $host = (string) $request->getHeader('host');
        if ($host === '') {
            $host = (string) $request->getServer('HTTP_HOST', '');
        }

        $host = strtolower(trim($host));

        if ($request->isSecure() && str_ends_with($host, ':443')) {
            return substr($host, 0, -4);
        }
        if (!$request->isSecure() && str_ends_with($host, ':80')) {
            return substr($host, 0, -3);
        }

        return $host;
    }

    /**
     * Re-serialize one member of a structured dictionary header.
     */
    private function dictionaryMember(string $headerValue, string $key): ?string
    {
        $members = StructuredFields::parseStringDictionary($headerValue);
        if (!isset($members[$key])) {
            return null;
        }

        $member = $members[$key];
        $out    = '"' . $member['value'] . '"';

        foreach ($member['params'] as $paramKey => $paramValue) {
            $out .= $paramValue === '?1'
                ? ';' . $paramKey
                // Token-shaped values (jwks_uri, cimd, directory) are not quoted.
                : ';' . $paramKey . '=' . (preg_match('/^[a-z][a-z0-9_.\-]*$/i', $paramValue)
                    ? $paramValue
                    : '"' . $paramValue . '"');
        }

        return $out;
    }
}
