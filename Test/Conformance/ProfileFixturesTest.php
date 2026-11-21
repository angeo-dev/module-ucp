<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Conformance;

use Angeo\Ucp\Model\Config;
use Angeo\Ucp\Model\Keys\JwkFormatter;
use Angeo\Ucp\Model\ProfileGenerator;
use Angeo\Ucp\Model\Service\McpShoppingBindingProvider;
use Angeo\Ucp\Model\Service\RestShoppingBindingProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Generates real business profiles for the official UCP checks.
 *
 * The workflow .github/workflows/ucp-official.yml sets UCP_FIXTURE_DIR,
 * runs this suite, then validates every written profile with the official
 * `ucp-schema` CLI against the 2026-08-25 schemas and serves it to the
 * official conformance suite (`ucp-conformance --suite=core`). Without the
 * variable the profiles are only generated and checked here.
 */
#[CoversNothing]
class ProfileFixturesTest extends TestCase
{
    private const BASE = 'https://shop.example.com';

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function scenarios(): array
    {
        return [
            // angeo/module-ucp + module-ucp-catalog
            'catalog' => ['catalog', [
                'catalogSearch' => true,
                'catalogLookup' => true,
            ]],
            // + module-ucp-checkout (+ MCP tools), with ES256 and Ed25519 keys
            'checkout' => ['checkout', [
                'catalogSearch' => true,
                'catalogLookup' => true,
                'checkout'      => true,
                'order'         => true,
                'fulfillment'   => true,
                'discount'      => true,
                'mcp'           => true,
                'keys'          => true,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('scenarios')]
    public function writes_the_profile(string $name, array $options): void
    {
        $profile = $this->generate($options);
        $json    = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        self::assertSame(Config::PROTOCOL_VERSION, $profile['ucp']['version']);
        self::assertJson($json);

        $dir = (string) getenv('UCP_FIXTURE_DIR');
        if ($dir === '') {
            return;
        }
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            self::fail('Cannot create ' . $dir);
        }
        file_put_contents($dir . '/' . $name . '.json', $json . "\n");
    }

    /**
     * @param array<string, mixed> $o
     * @return array<string, mixed>
     */
    private function generate(array $o): array
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('getRestEndpoint')->willReturn(self::BASE . Config::DEFAULT_REST_PATH);
        $config->method('getMcpEndpoint')->willReturn(!empty($o['mcp']) ? self::BASE . '/mcp' : '');
        $config->method('isCatalogSearchDeclared')->willReturn(!empty($o['catalogSearch']));
        $config->method('isCatalogLookupDeclared')->willReturn(!empty($o['catalogLookup']));
        $config->method('isCartDeclared')->willReturn(false);
        $config->method('isCheckoutDeclared')->willReturn(!empty($o['checkout']));
        $config->method('isOrderDeclared')->willReturn(!empty($o['order']));
        $config->method('isIdentityLinkingDeclared')->willReturn(false);
        $config->method('getIdentityLinkingScopes')->willReturn([]);
        $config->method('isFulfillmentDeclared')->willReturn(!empty($o['fulfillment']));
        $config->method('isDiscountDeclared')->willReturn(!empty($o['discount']));
        $config->method('getSupportedVersions')->willReturn([]);
        $config->method('getPaymentHandlers')->willReturn([]);
        $config->method('getPublicKeys')->willReturn(!empty($o['keys']) ? self::keys() : []);

        return (new ProfileGenerator($config, [
            new RestShoppingBindingProvider($config),
            new McpShoppingBindingProvider($config),
        ]))->generate();
    }

    /**
     * Public halves of the RFC 9421 Appendix B.1 test keys, with RFC 7638
     * thumbprint kids as angeo:ucp:keys:generate assigns them.
     *
     * @return array<int, array<string, string>>
     */
    private static function keys(): array
    {
        $keys = [
            ['kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig',
                'x' => 'qIVYZVLCrPZHGHjP17CTW0_-D9Lfw0EkjqF7xB4FivA',
                'y' => 'Mc4nN9LTDOBhfoUeg8Ye9WedFRhnZXZJA12Qp0zZ6F0'],
            ['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig',
                'x' => 'JrQLj5P_89iXES9-vFgrIy29clF9CC_oPPsw3c5D0bs'],
        ];

        $formatter = new JwkFormatter();
        foreach ($keys as $i => $key) {
            $keys[$i] = ['kid' => $formatter->thumbprint($key)] + $key;
        }

        return $keys;
    }
}
