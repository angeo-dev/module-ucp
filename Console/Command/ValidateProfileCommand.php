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
 * Validates the generated UCP business profile against the 2026-04-08 spec:
 *  - protocol version declared and correct
 *  - at least one service binding; every binding has version/spec/transport
 *  - all endpoint URLs use HTTPS (spec: endpoint MUST be HTTPS)
 *  - endpoints have no trailing slash (spec: SHOULD NOT)
 *  - every capability has version + spec + schema (spec: REQUIRED fields)
 *  - dev.ucp.* capabilities have spec/schema origins on https://ucp.dev
 *    (spec: Spec URL Binding — origin MUST match namespace authority)
 *  - no orphaned extensions (every `extends` target is present in the profile)
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

        // ── Service bindings ──────────────────────────────────────────────
        $services = $profile['ucp']['services'] ?? [];
        if (!is_array($services) || $services === []) {
            $errors[] = 'No service bindings declared. Configure a REST endpoint in admin.';
        } else {
            foreach ($services as $serviceName => $bindings) {
                if (!is_array($bindings)) {
                    continue;
                }
                foreach ($bindings as $binding) {
                    foreach (['version', 'spec', 'transport'] as $required) {
                        if (empty($binding[$required])) {
                            $errors[] = sprintf(
                                'Service "%s" binding is missing required field "%s".',
                                $serviceName,
                                $required
                            );
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
        $capabilities = $profile['ucp']['capabilities'] ?? [];
        if (is_array($capabilities)) {
            $declaredNames = array_keys($capabilities);

            foreach ($capabilities as $capName => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    // version, spec, schema are REQUIRED for capabilities.
                    foreach (['version', 'spec', 'schema'] as $required) {
                        if (empty($entry[$required])) {
                            $errors[] = sprintf(
                                'Capability "%s" is missing required field "%s".',
                                $capName,
                                $required
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
}
