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
 * Validates that the generated UCP profile is well-formed:
 *  - declares protocol version 2026-04-08
 *  - has at least one transport binding
 *  - has at least one capability OR signing key
 *  - JSON-encodes successfully under JSON_THROW_ON_ERROR
 *
 * Useful as a release check and as a cron healthcheck.
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
            ->setDescription('Validate the generated UCP profile structure')
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
        $errors = [];

        try {
            $profile = $this->profileGenerator->generate();
        } catch (\Throwable $e) {
            $output->writeln('<error>Profile generation threw: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (($profile['ucp']['version'] ?? null) !== Config::PROTOCOL_VERSION) {
            $errors[] = 'Missing or wrong protocol version. Expected ' . Config::PROTOCOL_VERSION;
        }

        $services = $profile['ucp']['services'] ?? [];
        if (!is_array($services) || $services === []) {
            $errors[] = 'No service bindings declared. Configure a REST endpoint in admin.';
        }

        $capabilities = $profile['ucp']['capabilities'] ?? [];
        $signingKeys = $profile['signing_keys'] ?? [];
        if ($capabilities === [] && $signingKeys === []) {
            $errors[] = 'Profile has no capabilities and no signing keys. '
                . 'Enable at least one capability in admin or run angeo:ucp:keys:generate.';
        }

        try {
            json_encode($profile, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $errors[] = 'Profile is not JSON-encodable: ' . $e->getMessage();
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
            '  ✓ Protocol %s, %d service binding(s), %d capability(ies), %d signing key(s)',
            Config::PROTOCOL_VERSION,
            count($services),
            count($capabilities),
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
