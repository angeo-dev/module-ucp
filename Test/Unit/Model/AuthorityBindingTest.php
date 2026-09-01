<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\AuthorityBinding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The accept/reject table below is transcribed directly from the
 * "Derivation algorithm" section of the 2026-08-25 overview, which is the
 * normative source for this check. A platform MUST reject entities that fail
 * it, and does so silently — so a regression here means capabilities vanish
 * from the negotiated set with nothing in any log to explain it.
 */
class AuthorityBindingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function specTableProvider(): array
    {
        return [
            // name, schema URL, expected valid
            'ucp.dev apex binds dev.ucp.* (prefix)' => [
                'dev.ucp.shopping.checkout',
                'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
                true,
            ],
            'aligned subdomain binds (prefix)' => [
                'dev.ucp.shopping.checkout',
                'https://shopping.ucp.dev/checkout.json',
                true,
            ],
            'vendor apex binds vendor namespace' => [
                'com.example.payments.installments',
                'https://example.com/installments.json',
                true,
            ],
            'handler host equals name (exact)' => [
                'com.example.pay',
                'https://pay.example.com/handler.json',
                true,
            ],
            'parent authority also binds handler' => [
                'com.example.pay',
                'https://example.com/handler.json',
                true,
            ],
            'unrelated host is rejected' => [
                'com.example.pay',
                'https://evil.example/handler.json',
                false,
            ],
            'unrelated host cannot claim dev.ucp' => [
                'dev.ucp.shopping.checkout',
                'https://evil.example/checkout.json',
                false,
            ],
            'textual but not label-aligned prefix is rejected' => [
                'com.examplecorp.pay',
                'https://example.com/handler.json',
                false,
            ],
            'shared CDN subdomain is rejected' => [
                'com.example.pay',
                'https://cdn.example.com/handler.json',
                false,
            ],
        ];
    }

    #[Test]
    #[DataProvider('specTableProvider')]
    public function matches_the_specs_published_table(
        string $name,
        string $schemaUrl,
        bool $expected
    ): void {
        [$valid] = AuthorityBinding::check($name, $schemaUrl);

        self::assertSame($expected, $valid);
    }

    #[Test]
    public function userinfo_decoy_is_rejected(): void
    {
        // The spec calls this out by name: substring matching on the raw URL
        // is NOT permitted, because https://ucp.dev@evil.example/x.json has
        // host evil.example. A naive str_contains('ucp.dev') check accepts it.
        [$valid, $reason] = AuthorityBinding::check(
            'dev.ucp.shopping.checkout',
            'https://ucp.dev@evil.example/x.json'
        );

        self::assertFalse($valid);
        self::assertStringContainsString('evil.example', $reason);
    }

    #[Test]
    public function ip_literal_host_is_not_an_authority(): void
    {
        [$valid] = AuthorityBinding::check(
            'com.example.pay',
            'https://203.0.113.10/handler.json'
        );

        self::assertFalse($valid);
    }

    #[Test]
    public function single_label_host_is_not_an_authority(): void
    {
        [$valid] = AuthorityBinding::check('com.example.pay', 'https://localhost/handler.json');

        self::assertFalse($valid);
    }

    #[Test]
    public function non_https_scheme_is_rejected(): void
    {
        [$valid, $reason] = AuthorityBinding::check(
            'dev.ucp.shopping.checkout',
            'http://ucp.dev/checkout.json'
        );

        self::assertFalse($valid);
        self::assertStringContainsString('https', $reason);
    }

    #[Test]
    public function host_is_normalised_before_reversal(): void
    {
        // Uppercase and a trailing root dot are both legal in a URL and must
        // not change the derived authority.
        [$valid] = AuthorityBinding::check(
            'dev.ucp.shopping.checkout',
            'https://UCP.DEV./2026-08-25/schemas/shopping/checkout.json'
        );

        self::assertTrue($valid);
    }

    #[Test]
    public function port_is_ignored_when_deriving_the_authority(): void
    {
        [$valid] = AuthorityBinding::check(
            'com.example.pay',
            'https://example.com:8443/handler.json'
        );

        self::assertTrue($valid);
    }
}
