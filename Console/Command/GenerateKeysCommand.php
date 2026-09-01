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
 * Generates a signing keypair, persists ONLY the public JWK to
 * core_config_data, and prints the private PEM to stdout exactly once.
 *
 * Changes in 2.0.0:
 *  - `--type` selects es256 (default), es384, or ed25519. Ed25519 is what
 *    the spec RECOMMENDS for Web Bot Auth interop; ES256 remains the
 *    universal baseline and is what AP2 mandate signing needs.
 *  - `--add` APPENDS a key to the profile's `keys[]` array instead of
 *    replacing it. This is what makes zero-downtime rotation possible:
 *    publish the new key, move signing over to it, and only then drop the
 *    old one. Under 1.x every generate replaced the single stored key, so
 *    any signature still in flight against the old kid failed immediately.
 *  - `--kid` overrides the key identifier. Left alone, the kid is the
 *    RFC 7638 JWK thumbprint, which the spec REQUIRES for keys used in
 *    dual-audience Web Bot Auth signatures.
 */
class GenerateKeysCommand extends Command
{
    private const NAME      = 'angeo:ucp:keys:generate';
    private const OPT_FORCE = 'force';
    private const OPT_TYPE  = 'type';
    private const OPT_ADD   = 'add';
    private const OPT_KID   = 'kid';

    public function __construct(
        private readonly KeyGenerator         $keyGenerator,
        private readonly ConfigWriter         $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TypeListInterface    $cacheTypeList
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
            ->setDescription(
                'Generate a UCP signing keypair. The public JWK is saved to config '
                . 'and published in the profile\'s keys[] array; the private PEM is '
                . 'printed once and never stored.'
            )
            ->addOption(
                self::OPT_TYPE,
                't',
                InputOption::VALUE_REQUIRED,
                'Key type: ' . implode(' | ', KeyGenerator::TYPES),
                KeyGenerator::TYPE_ES256
            )
            ->addOption(
                self::OPT_ADD,
                'a',
                InputOption::VALUE_NONE,
                'Append to keys[] instead of replacing it (zero-downtime rotation)'
            )
            ->addOption(
                self::OPT_KID,
                null,
                InputOption::VALUE_REQUIRED,
                'Custom key id. Default: RFC 7638 JWK thumbprint (recommended)'
            )
            ->addOption(
                self::OPT_FORCE,
                'f',
                InputOption::VALUE_NONE,
                'Replace the existing keys[] without confirmation'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $append = (bool) $input->getOption(self::OPT_ADD);
        $force  = (bool) $input->getOption(self::OPT_FORCE);

        $existing = $this->readExistingKeys();

        if ($existing !== [] && !$append && !$force) {
            $output->writeln(sprintf(
                '<error>%d UCP signing key(s) are already published.</error>',
                count($existing)
            ));
            $output->writeln('');
            $output->writeln('  <info>--add</info>    publish an additional key (recommended: rotate');
            $output->writeln('            without invalidating signatures still in flight)');
            $output->writeln('  <info>--force</info>  replace every published key');
            $output->writeln('');
            $output->writeln(
                '<comment>A key is only effectively revoked once it is absent from keys[]. '
                . 'Replacing is therefore also how you revoke — but do it after, not '
                . 'before, signing has moved to the new key.</comment>'
            );
            return Command::FAILURE;
        }

        try {
            $keypair = $this->keyGenerator->generate(
                (string) $input->getOption(self::OPT_TYPE),
                (string) ($input->getOption(self::OPT_KID) ?? '')
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Key generation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $keys = $append ? $existing : [];

        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $keypair['kid']) {
                $output->writeln(sprintf(
                    '<error>A key with kid "%s" is already published. '
                    . 'Pass a different --kid, or omit --add to replace.</error>',
                    $keypair['kid']
                ));
                return Command::FAILURE;
            }
        }

        $keys[] = $keypair['jwk'];

        try {
            $this->configWriter->saveConfig(
                Config::XML_PATH_SIGNING_KEY_JWK,
                json_encode($keys, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'default',
                0
            );
            // Mark dirty rather than flush — avoids evicting unrelated config.
            $this->cacheTypeList->invalidate('config');
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to persist public JWK: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $this->printSuccess($output, $keypair, count($keys));
        return Command::SUCCESS;
    }

    /**
     * Read the currently published JWKs at DEFAULT scope — the scope
     * ConfigWriter::saveConfig() writes to. Reading at store scope can
     * return an empty string even when a key exists, which under 1.0.0
     * let the command silently overwrite a live key.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readExistingKeys(): array
    {
        $stored = (string) $this->scopeConfig->getValue(
            Config::XML_PATH_SIGNING_KEY_JWK,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        if (trim($stored) === '') {
            return [];
        }

        try {
            $decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    /**
     * @param array{kid: string, type: string, alg: string, crv: string,
     *              private_pem: string, jwk: array<string, string>} $keypair
     */
    private function printSuccess(OutputInterface $output, array $keypair, int $total): void
    {
        $output->writeln('<info>UCP signing key generated successfully.</info>');
        $output->writeln('');
        $output->writeln('  kid  : <comment>' . $keypair['kid'] . '</comment>');
        $output->writeln('  kty  : ' . $keypair['jwk']['kty']);
        $output->writeln('  crv  : ' . $keypair['crv']);
        $output->writeln('  alg  : ' . $keypair['alg']);
        $output->writeln('');
        $output->writeln(sprintf(
            'The profile now publishes <comment>%d</comment> key(s) in its top-level '
            . '<comment>keys[]</comment> array.',
            $total
        ));

        if ($keypair['type'] === KeyGenerator::TYPE_ED25519) {
            $output->writeln('');
            $output->writeln(
                '<comment>Ed25519 covers Web Bot Auth interop but NOT AP2 mandate signing, '
                . 'which requires ES256. If you sign AP2 mandates, add an ES256 key too:'
            );
            $output->writeln('  bin/magento angeo:ucp:keys:generate --type=es256 --add</comment>');
        }

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
