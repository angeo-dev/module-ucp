<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model\Signature;

use Angeo\Ucp\Model\Signature\StructuredFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StructuredFields::class)]
class StructuredFieldsTest extends TestCase
{
    #[Test]
    public function parses_signature_input_with_two_members(): void
    {
        $parsed = StructuredFields::parseInnerListDictionary(
            'sig1=("@method" "@authority" "signature-agent";key="sig1");keyid="k1";created=1, '
            . 'sig2=("@path");keyid="k2";tag="web-bot-auth"'
        );

        self::assertSame(['sig1', 'sig2'], array_keys($parsed));
        self::assertSame('@method', $parsed['sig1']['components'][0]['name']);
        self::assertSame(['key' => 'sig1'], $parsed['sig1']['components'][2]['params']);
        self::assertSame(['keyid' => 'k1', 'created' => '1'], $parsed['sig1']['params']);
        self::assertSame('web-bot-auth', $parsed['sig2']['params']['tag']);
    }

    #[Test]
    public function component_names_are_lowercased(): void
    {
        $parsed = StructuredFields::parseInnerListDictionary('s=("Content-Digest");keyid="k"');

        self::assertSame('content-digest', $parsed['s']['components'][0]['name']);
    }

    #[Test]
    public function parses_byte_sequences_and_drops_invalid_base64(): void
    {
        $parsed = StructuredFields::parseByteSequenceDictionary('a=:AQID:, b=:not base64!:, c=AQID');

        self::assertSame(['a' => "\x01\x02\x03"], $parsed);
    }

    #[Test]
    public function string_dictionary_keeps_semicolons_inside_quotes(): void
    {
        $parsed = StructuredFields::parseStringDictionary('sig1="https://a.example/p;x=1";type=jwks_uri');

        self::assertSame('https://a.example/p;x=1', $parsed['sig1']['value']);
        self::assertSame(['type' => 'jwks_uri'], $parsed['sig1']['params']);
    }

    #[Test]
    public function raw_members_echo_the_original_text(): void
    {
        $header = 'sig1=("@method" "@path");created=1;keyid="a,b", sig2=("@path");nonce="x;y"';

        self::assertSame([
            'sig1' => '("@method" "@path");created=1;keyid="a,b"',
            'sig2' => '("@path");nonce="x;y"',
        ], StructuredFields::rawMembers($header));
    }

    #[Test]
    public function boolean_parameters_parse_as_true(): void
    {
        $parsed = StructuredFields::parseStringDictionary('a="v";flag');

        self::assertSame(['flag' => '?1'], $parsed['a']['params']);
    }
}
