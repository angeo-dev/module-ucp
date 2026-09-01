<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Console\Command;

use Angeo\Ucp\Api\ProfileGeneratorInterface;
use Angeo\Ucp\Model\AuthorityBinding;
use Angeo\Ucp\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento angeo:ucp:validate
 *
 * Validates the generated business profile against the UCP 2026-08-25 spec.
 * Every check below is derived from the official JSON Schemas in the spec
 * repository at tag v2026-08-25 (schemas/profile.json, schemas/ucp.json,
 * service.json, capability.json, payment_handler.json) plus the normative
 * prose in specification/overview.
 *
 * Checks:
 *  - protocol version declared and correct
 *  - `services` and `payment_handlers` PRESENT in the ucp object
 *    (business_schema: REQUIRED even when empty)
 *  - empty registries serialize as JSON objects `{}`, never arrays `[]`
 *  - registry keys match the reverse-domain pattern
 *  - every service binding has version + transport; rest/mcp/a2a bindings
 *    carry an `endpoint`; every endpoint is HTTPS with no trailing slash
 *  - every capability has BOTH `version` and `schema`
 *    (capability.json#/$defs/business_schema adds required: ["schema"] in
 *    2026-08-25 — platforms fetch and compose it during negotiation, so a
 *    capability without one cannot be activated)
 *  - AUTHORITY BINDING on every `schema` URL, using the spec's derivation
 *    algorithm rather than a string prefix (see Model\AuthorityBinding).
 *    A platform MUST reject any entity that fails this, so a profile that
 *    fails it locally is a profile whose capabilities silently disappear.
 *  - `spec` URLs are https but NOT authority-bound: the 2026-08-25 spec
 *    explicitly moved documentation off the machine trust path, so a docs
 *    subdomain or third-party host is legitimate. 1.x rejected those.
 *  - no orphaned extensions (every `extends` target is present)
 *  - every payment handler entry has `id` + `version`
 *  - keys published in the canonical top-level `keys[]`, validated per key
 *    type (EC needs crv/x/y, OKP needs crv/x), with `alg` consistent with
 *    `crv`, and carrying no private material
 *  - the legacy 1.x `signing_keys` field is reported as an error: no UCP
 *    verifier reads it, so a profile using it publishes no usable key
 *  - profile JSON-encodes under JSON_THROW_ON_ERROR
 *
 * Exit code 0 on pass (warnings allowed), 1 on failure.
 */
class ValidateProfileCommand extends Command
{
    private const NAME = 'angeo:ucp:validate';

    public function __construct(
        private readonly ProfileGeneratorInterface $profileGenerator
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription('Validate the generated UCP profile against the '
                . Config::PROTOCOL_VERSION . ' spec')
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Print the generated profile JSON on success'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors   = [];
        $warnings = [];

        try {
            $profile = $this->profileGenerator->generate();
        } catch (\Throwable $e) {
            $output->writeln('<error>Profile generation threw: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // ── Protocol version ──────────────────────────────────────────────
        if (($profile['ucp']['version'] ?? null) !== Config::PROTOCOL_VERSION) {
            $errors[] = 'Missing or incorrect protocol version. Expected ' . Config::PROTOCOL_VERSION;
        }

        // ── Required registry keys (business_schema) ──────────────────────
        // Per ucp.json#/$defs/business_schema, `services` and
        // `payment_handlers` MUST be present in the ucp object (empty is
        // allowed, absent is not).
        foreach (['services', 'payment_handlers'] as $requiredKey) {
            if (!array_key_exists($requiredKey, $profile['ucp'] ?? [])) {
                $errors[] = sprintf(
                    'ucp.%s key is missing. The business profile schema requires '
                    . 'it to be present even when empty.',
                    $requiredKey
                );
            }
        }

        // ── Service bindings ──────────────────────────────────────────────
        $services = self::registryToArray($profile['ucp']['services'] ?? []);
        if ($services === []) {
            $warnings[] = 'No service bindings declared. The profile is schema-valid '
                . 'but AI agents have no endpoint to call. Configure a REST endpoint '
                . 'in admin when your UCP service implementation is live.';
        } else {
            foreach ($services as $serviceName => $bindings) {
                if (!preg_match(Config::REVERSE_DOMAIN_PATTERN, (string) $serviceName)) {
                    $errors[] = sprintf(
                        'Service key "%s" is not a valid reverse-domain name.',
                        $serviceName
                    );
                }
                if (!is_array($bindings)) {
                    $errors[] = sprintf(
                        'Service "%s" value must be an array of transport bindings.',
                        $serviceName
                    );
                    continue;
                }
                foreach ($bindings as $binding) {
                    foreach (['version', 'transport'] as $required) {
                        if (empty($binding[$required])) {
                            $errors[] = sprintf(
                                'Service "%s" binding is missing required field "%s".',
                                $serviceName,
                                $required
                            );
                        }
                    }

                    // business_schema: rest/mcp/a2a transports REQUIRE endpoint.
                    $transport = (string) ($binding['transport'] ?? '');
                    if (in_array($transport, ['rest', 'mcp', 'a2a'], true)
                        && empty($binding['endpoint'])
                    ) {
                        $errors[] = sprintf(
                            'Service "%s" %s binding is missing "endpoint" '
                            . '(REQUIRED for this transport in business profiles).',
                            $serviceName,
                            $transport
                        );
                    }

                    // Service bindings that declare a schema are subject to
                    // the same authority binding as capabilities.
                    $bindingSchema = (string) ($binding['schema'] ?? '');
                    if ($bindingSchema !== '') {
                        [$bound, $reason] = AuthorityBinding::check(
                            (string) $serviceName,
                            $bindingSchema
                        );
                        if (!$bound) {
                            $errors[] = 'Service "' . $serviceName . '": ' . $reason;
                        }
                    }

                    $endpoint = (string) ($binding['endpoint'] ?? '');
                    if ($endpoint !== '') {
                        if (!str_starts_with(strtolower($endpoint), 'https://')) {
                            $errors[] = sprintf(
                                'Service "%s" endpoint "%s" does not use HTTPS (spec: MUST).',
                                $serviceName,
                                $endpoint
                            );
                        }
                        if (str_ends_with($endpoint, '/')) {
                            $warnings[] = sprintf(
                                'Service "%s" endpoint has a trailing slash (spec: SHOULD NOT).',
                                $serviceName
                            );
                        }
                    }
                }
            }
        }

        // ── Capabilities ──────────────────────────────────────────────────
        $capabilities = self::registryToArray($profile['ucp']['capabilities'] ?? []);
        if (true) {
            $declaredNames = array_keys($capabilities);

            foreach ($capabilities as $capName => $entries) {
                if (!preg_match(Config::REVERSE_DOMAIN_PATTERN, (string) $capName)) {
                    $errors[] = sprintf(
                        'Capability key "%s" is not a valid reverse-domain name.',
                        $capName
                    );
                }
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    // 2026-08-25: business capabilities REQUIRE version AND
                    // schema. `schema` was optional at 2026-04-08; it is now
                    // what the platform fetches and composes during
                    // negotiation, so a capability without one is inert.
                    foreach (['version', 'schema'] as $required) {
                        if (empty($entry[$required])) {
                            $errors[] = sprintf(
                                'Capability "%s" is missing required field "%s" '
                                . '(capability.json#/$defs/business_schema).',
                                $capName,
                                $required
                            );
                        }
                    }

                    if (empty($entry['spec'])) {
                        $warnings[] = sprintf(
                            'Capability "%s" has no "spec" URL. Optional, but it is '
                            . 'the only human-readable pointer an integrator gets.',
                            $capName
                        );
                    }

                    // Authority binding applies to `schema` only.
                    $schemaUrl = (string) ($entry['schema'] ?? '');
                    if ($schemaUrl !== '') {
                        [$bound, $reason] = AuthorityBinding::check((string) $capName, $schemaUrl);
                        if (!$bound) {
                            $errors[] = 'Capability "' . $capName . '": ' . $reason;
                        }
                    }

                    // `spec` is documentation, off the machine trust path:
                    // it MUST be https but MAY live on any host.
                    $specUrl = (string) ($entry['spec'] ?? '');
                    if ($specUrl !== '' && !str_starts_with(strtolower($specUrl), 'https://')) {
                        $errors[] = sprintf(
                            'Capability "%s" spec URL must be https. Got: %s',
                            $capName,
                            $specUrl
                        );
                    }

                    // Orphaned-extension check.
                    if (isset($entry['extends'])) {
                        $parents = is_array($entry['extends'])
                            ? $entry['extends']
                            : [$entry['extends']];
                        $hasParent = array_intersect($parents, $declaredNames) !== [];
                        if (!$hasParent) {
                            $errors[] = sprintf(
                                'Extension "%s" has no declared parent capability '
                                . '(extends: %s). Orphaned extensions are pruned '
                                . 'during negotiation and must not be advertised.',
                                $capName,
                                implode(', ', $parents)
                            );
                        }
                    }
                }
            }
        }

        // ── Payment handlers ──────────────────────────────────────────────
        $paymentHandlers = self::registryToArray($profile['ucp']['payment_handlers'] ?? []);
        foreach ($paymentHandlers as $handlerName => $entries) {
            if (!preg_match(Config::REVERSE_DOMAIN_PATTERN, (string) $handlerName)) {
                $errors[] = sprintf(
                    'Payment handler key "%s" is not a valid reverse-domain name.',
                    $handlerName
                );
            }
            if (!is_array($entries) || !array_is_list($entries)) {
                $errors[] = sprintf(
                    'payment_handlers["%s"] must be a JSON array of handler entries.',
                    $handlerName
                );
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if (empty($entry['id']) || !is_string($entry['id'])) {
                    $errors[] = sprintf(
                        'payment_handlers["%s"] entry is missing required string "id".',
                        $handlerName
                    );
                }
                if (empty($entry['version'])
                    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $entry['version'])
                ) {
                    $errors[] = sprintf(
                        'payment_handlers["%s"] entry is missing a required '
                        . 'YYYY-MM-DD "version".',
                        $handlerName
                    );
                }

                // Handlers declare a schema where the handler defines one;
                // when present it is authority-bound like any other entity.
                $handlerSchema = (string) ($entry['schema'] ?? '');
                if ($handlerSchema !== '') {
                    [$bound, $reason] = AuthorityBinding::check(
                        (string) $handlerName,
                        $handlerSchema
                    );
                    if (!$bound) {
                        $errors[] = 'Payment handler "' . $handlerName . '": ' . $reason;
                    }
                }
            }
        }

        // ── supported_versions sanity ─────────────────────────────────────
        $supportedVersions = $profile['ucp']['supported_versions'] ?? [];
        if (is_array($supportedVersions)) {
            foreach ($supportedVersions as $ver => $uri) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ver)) {
                    $errors[] = sprintf('supported_versions key "%s" is not YYYY-MM-DD.', $ver);
                }
                if (!str_starts_with(strtolower((string) $uri), 'https://')) {
                    $errors[] = sprintf('supported_versions URI for "%s" is not HTTPS.', $ver);
                }
            }
        }

        // ── Signing keys ──────────────────────────────────────────────────
        // 2026-08-25 moved keys to the canonical top-level `keys[]` JWK Set.
        // A profile still using the 1.x `signing_keys` field publishes keys
        // that no verifier will ever read.
        if (array_key_exists('signing_keys', $profile)) {
            $errors[] = 'Profile uses the legacy "signing_keys" field. Since '
                . 'protocol version 2026-08-25 the canonical field is the '
                . 'top-level "keys" array (RFC 7517 JWK Set); no UCP verifier '
                . 'reads "signing_keys". Upgrade angeo/module-ucp, or move the '
                . 'entries into "keys".';
        }

        $keys = $profile['keys'] ?? [];
        if (!is_array($keys)) {
            $errors[] = 'Profile "keys" must be an array (an RFC 7517 JWK Set).';
            $keys = [];
        }

        if ($capabilities === [] && $keys === []) {
            $warnings[] = 'Profile has no capabilities and no signing keys. '
                . 'AI agents can discover the endpoint but cannot verify signed '
                . 'responses. Run angeo:ucp:keys:generate to publish a key.';
        }

        $seenKids = [];

        foreach ($keys as $key) {
            if (!is_array($key)) {
                $errors[] = 'Every entry in "keys" must be a JWK object.';
                continue;
            }

            // profile.json#/$defs/jwk_public_key: kid + kty always REQUIRED.
            foreach (['kid', 'kty'] as $requiredField) {
                if (empty($key[$requiredField]) || !is_string($key[$requiredField])) {
                    $errors[] = sprintf(
                        'Public key is missing required member "%s".',
                        $requiredField
                    );
                }
            }

            $kid = (string) ($key['kid'] ?? 'unknown');
            $kty = (string) ($key['kty'] ?? '');
            $crv = isset($key['crv']) && is_string($key['crv']) ? $key['crv'] : '';
            $alg = isset($key['alg']) && is_string($key['alg']) ? $key['alg'] : '';

            // Consumers select keys by kid, so a duplicate makes resolution
            // ambiguous even though each entry validates on its own.
            if ($kid !== 'unknown') {
                if (isset($seenKids[$kid])) {
                    $errors[] = sprintf(
                        'Duplicate kid "%s" in keys[]. Consumers resolve keys by '
                        . 'kid, so duplicates make verification ambiguous.',
                        $kid
                    );
                }
                $seenKids[$kid] = true;
            }

            // Per-type required members.
            $requiredByKty = ['EC' => ['crv', 'x', 'y'], 'OKP' => ['crv', 'x']];
            foreach ($requiredByKty[$kty] ?? [] as $member) {
                if (empty($key[$member]) || !is_string($key[$member])) {
                    $errors[] = sprintf(
                        'Key "%s" (%s) is missing required member "%s".',
                        $kid,
                        $kty,
                        $member
                    );
                }
            }

            // The schema pins alg to crv for every well-known curve.
            $algByCrv = ['P-256' => 'ES256', 'P-384' => 'ES384', 'Ed25519' => 'EdDSA'];
            if ($crv !== '' && $alg !== ''
                && isset($algByCrv[$crv]) && $algByCrv[$crv] !== $alg
            ) {
                $errors[] = sprintf(
                    'Key "%s" declares alg "%s" with crv "%s"; the schema pins %s.',
                    $kid,
                    $alg,
                    $crv,
                    $algByCrv[$crv]
                );
            }

            if ($kty !== '' && !in_array($kty, ['EC', 'OKP'], true)) {
                $warnings[] = sprintf(
                    'Key "%s" uses key type "%s", which is outside the two '
                    . 'well-known UCP types (EC, OKP). The vocabulary is open, '
                    . 'so this is legal — but verifiers that do not recognise it '
                    . 'will skip the key.',
                    $kid,
                    $kty
                );
            }

            $privateFields = ['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'];
            if (array_intersect_key($key, array_flip($privateFields)) !== []) {
                $errors[] = sprintf(
                    'Key "%s" contains PRIVATE key material. The profile is public: '
                    . 'rotate this key immediately.',
                    $kid
                );
            }
        }

        // ── JSON-encodability ─────────────────────────────────────────────
        try {
            json_encode($profile, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = 'Profile is not JSON-encodable: ' . $e->getMessage();
        }

        // ── Output ────────────────────────────────────────────────────────
        foreach ($warnings as $warning) {
            $output->writeln('<comment>  ⚠ ' . $warning . '</comment>');
        }

        if ($errors !== []) {
            $output->writeln('<error>UCP profile validation FAILED:</error>');
            foreach ($errors as $error) {
                $output->writeln('  ✗ ' . $error);
            }
            return Command::FAILURE;
        }

        $output->writeln('<info>UCP profile validation passed.</info>');
        $output->writeln(sprintf(
            '  ✓ Protocol %s | %d service binding(s) | %d capability(ies) | %d published key(s)',
            Config::PROTOCOL_VERSION,
            count($services),
            is_array($capabilities) ? count($capabilities) : 0,
            count($keys)
        ));

        if ($input->getOption('json')) {
            $output->writeln('');
            $output->writeln(json_encode(
                $profile,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Normalise a registry value to an array for inspection.
     *
     * Empty registries are emitted as stdClass so they JSON-encode as `{}`;
     * cast them back to arrays for validation.
     *
     * @return array<string|int, mixed>
     */
    private static function registryToArray(mixed $registry): array
    {
        if ($registry instanceof \stdClass) {
            return (array) $registry;
        }
        return is_array($registry) ? $registry : [];
    }
}
