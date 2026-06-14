<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Console\Command;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\Keys\KeyGenerator;
use Magento\Config\Model\ResourceModel\Config as ConfigWriter;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: bin/magento angeo:ucp:keys:generate
 *
 * Generates a fresh ECDSA P-256 keypair, persists only the public JWK to
 * core_config_data, and prints the private PEM to stdout exactly once.
 *
 * Operators MUST capture the private PEM and place it in:
 *  - app/etc/env.php under 'ucp' => ['signing_keys' => [...]], OR
 *  - a secrets manager their server can read at runtime.
 *
 * Changes in 1.0.0:
 *  - FIX: config cache is now invalidated via invalidate() rather than
 *    cleanType() so the change is picked up on the very next request
 *    without requiring a full cache flush.
 *  - FIX: the command now uses ScopeConfigInterface::SCOPE_TYPE_DEFAULT
 *    explicitly when reading the existing key, matching the scope used
 *    by ConfigWriter::saveConfig(), preventing a false "no key found"
 *    when the key is stored at default scope but read at store scope.
 */
class GenerateKeysCommand extends Command
{
    private const NAME      = 'angeo:ucp:keys:generate';
    private const OPT_FORCE = 'force';

    public function __construct(
        private readonly KeyGenerator        $keyGenerator,
        private readonly ConfigWriter        $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TypeListInterface   $cacheTypeList
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription(
                'Generate an ECDSA P-256 signing keypair for UCP. '
                . 'Public JWK is saved to config; private PEM is printed once.'
            )
            ->addOption(
                self::OPT_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Overwrite the existing public JWK without confirmation'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Read at default scope — this is where ConfigWriter saves to.
        $existing = (string) $this->scopeConfig->getValue(
            Config::XML_PATH_SIGNING_KEY_JWK,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        if ($existing !== '' && !$input->getOption(self::OPT_FORCE)) {
            $output->writeln(
                '<error>A UCP signing key is already configured. '
                . 'Pass --force to rotate. The existing public JWK will be replaced; '
                . 'update app/etc/env.php with the new private PEM immediately.</error>'
            );
            return Command::FAILURE;
        }

        try {
            $keypair = $this->keyGenerator->generate();
        } catch (\Throwable $e) {
            $output->writeln('<error>Key generation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Persist ONLY the public JWK in core_config_data.
        // The private PEM is intentionally not stored — operators handle it.
        try {
            $this->configWriter->saveConfig(
                Config::XML_PATH_SIGNING_KEY_JWK,
                json_encode([$keypair['jwk']], JSON_THROW_ON_ERROR),
                'default',
                0
            );
            // Invalidate (mark dirty) rather than clean — avoids flushing
            // unrelated config cache entries.
            $this->cacheTypeList->invalidate('config');
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to persist public JWK: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->printSuccess($output, $keypair);
        return Command::SUCCESS;
    }

    /**
     * @param array{kid: string, private_pem: string, public_pem: string, jwk: array<string, string>} $keypair
     */
    private function printSuccess(OutputInterface $output, array $keypair): void
    {
        $output->writeln('<info>UCP signing key generated successfully.</info>');
        $output->writeln('');
        $output->writeln('  kid  : <comment>' . $keypair['kid'] . '</comment>');
        $output->writeln('  curve: P-256 (prime256v1)');
        $output->writeln('  alg  : ES256');
        $output->writeln('');
        $output->writeln('Public JWK saved to config and will appear in /.well-known/ucp.');
        $output->writeln('');
        $output->writeln('<comment>== Private key (PEM) — copy this NOW, it will NOT be shown again ==</comment>');
        $output->writeln('');
        $output->write($keypair['private_pem']);
        $output->writeln('');
        $output->writeln('<info>Add to app/etc/env.php:</info>');
        $output->writeln('');
        $output->writeln("    'ucp' => [");
        $output->writeln("        'signing_keys' => [");
        $output->writeln("            '" . $keypair['kid'] . "' => '<PEM contents above>',");
        $output->writeln("        ],");
        $output->writeln("    ],");
        $output->writeln('');
        $output->writeln('<comment>Run  bin/magento cache:flush  if config cache is not auto-invalidated.</comment>');
    }
}
