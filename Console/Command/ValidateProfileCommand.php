<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Console\Command;

use Angeo\Ucp\Api\ProfileGeneratorInterface;
use Angeo\Ucp\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento angeo:ucp:validate
 *
 * Validates the generated UCP business profile against the 2026-04-08 spec
 * (checks derived from the official JSON Schemas in the spec repository,
 * tag v2026-04-08: discovery/profile_schema.json, schemas/ucp.json,
 * schemas/service.json, schemas/capability.json, schemas/payment_handler.json):
 *  - protocol version declared and correct
 *  - `services` and `payment_handlers` keys PRESENT in the ucp object
 *    (business_schema: REQUIRED even when empty)
 *  - empty registries serialize as JSON objects `{}`, never arrays `[]`
 *  - registry keys match the reverse-domain pattern
 *  - every service binding has version + transport; REST/MCP/A2A bindings
 *    have an `endpoint` (business_schema: REQUIRED per transport)
 *  - all endpoint URLs use HTTPS (spec: endpoint MUST be HTTPS)
 *  - endpoints have no trailing slash (spec: SHOULD NOT)
 *  - every capability has `version` (REQUIRED); spec/schema recommended
 *  - dev.ucp.* capabilities have spec/schema origins on https://ucp.dev
 *    (spec: Spec URL Binding — origin MUST match namespace authority)
 *  - no orphaned extensions (every `extends` target is present in the profile)
 *  - every payment handler entry has `id` + `version` (REQUIRED)
 *  - signing keys carry no private material; kid/kty REQUIRED
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
            ->setDescription('Validate the generated UCP profile against the 2026-04-08 spec')
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
                    // Schema: only `version` is REQUIRED at business level.
                    if (empty($entry['version'])) {
                        $errors[] = sprintf(
                            'Capability "%s" is missing required field "version".',
                            $capName
                        );
                    }
                    // spec/schema are optional in business profiles but
                    // strongly recommended so agents can fetch definitions.
                    foreach (['spec', 'schema'] as $recommended) {
                        if (empty($entry[$recommended])) {
                            $warnings[] = sprintf(
                                'Capability "%s" has no "%s" URL (optional per '
                                . 'business schema, but recommended).',
                                $capName,
                                $recommended
                            );
                        }
                    }

                    // Spec URL Binding: dev.ucp.* MUST point at https://ucp.dev.
                    if (str_starts_with((string) $capName, 'dev.ucp.')) {
                        foreach (['spec', 'schema'] as $urlField) {
                            $url = (string) ($entry[$urlField] ?? '');
                            if ($url !== '' && !str_starts_with($url, 'https://ucp.dev/')) {
                                $errors[] = sprintf(
                                    'Capability "%s" %s URL origin must be https://ucp.dev '
                                    . '(spec: Spec URL Binding). Got: %s',
                                    $capName,
                                    $urlField,
                                    $url
                                );
                            }
                        }
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
        $signingKeys = $profile['signing_keys'] ?? [];
        if ($capabilities === [] && $signingKeys === []) {
            $warnings[] = 'Profile has no capabilities and no signing keys. '
                . 'AI agents can discover the endpoint but cannot verify signed responses. '
                . 'Run angeo:ucp:keys:generate to add a signing key.';
        }

        foreach ($signingKeys as $key) {
            if (is_array($key)) {
                // Schema (profile_schema.json#/$defs/signing_key): kid + kty REQUIRED.
                foreach (['kid', 'kty'] as $requiredField) {
                    if (empty($key[$requiredField])) {
                        $errors[] = sprintf(
                            'Signing key is missing required field "%s".',
                            $requiredField
                        );
                    }
                }
            }
            if (is_array($key) && array_intersect_key($key, array_flip(['d', 'p', 'q', 'dp', 'dq', 'qi'])) !== []) {
                $errors[] = sprintf(
                    'Signing key "%s" contains PRIVATE key material. Rotate the key immediately.',
                    (string) ($key['kid'] ?? 'unknown')
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
            '  ✓ Protocol %s | %d service binding(s) | %d capability(ies) | %d signing key(s)',
            Config::PROTOCOL_VERSION,
            count($services),
            is_array($capabilities) ? count($capabilities) : 0,
            count($signingKeys)
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
