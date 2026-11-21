<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\Ucp\Test\Unit\Model;

use Angeo\Ucp\Model\AgentHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentHeader::class)]
class AgentHeaderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function headers(): array
    {
        return [
            // Shape used in every example of the 2026-08-25 spec.
            'spec example' => [
                'profile="https://platform.example/.well-known/ucp"',
                'https://platform.example/.well-known/ucp',
                '',
            ],
            // Shape the official conformance suite sends.
            'version as parameter' => [
                'profile="https://agent.example/p"; version="2026-08-25"',
                'https://agent.example/p',
                '2026-08-25',
            ],
            'version as member' => [
                'profile="https://agent.example/p", version="2026-04-08"',
                'https://agent.example/p',
                '2026-04-08',
            ],
            'conformance placeholder profile' => [
                'profile="..."; version="2099-01-01"',
                '...',
                '2099-01-01',
            ],
            'semicolon and comma inside the quoted URL' => [
                'profile="https://agent.example/p;v=1,2?a=b"; version="2026-08-25"',
                'https://agent.example/p;v=1,2?a=b',
                '2026-08-25',
            ],
            'upper-case key is tolerated' => [
                'Profile="https://agent.example/p"',
                'https://agent.example/p',
                '',
            ],
            'empty header' => ['', '', ''],
            // A bare URL is not an RFC 8941 dictionary; it is not a profile.
            'bare URL is not accepted' => ['https://agent.example/p', '', ''],
            'other keys only' => ['agent="x"', '', ''],
        ];
    }

    #[Test]
    #[DataProvider('headers')]
    public function parses_profile_and_version(string $header, string $profile, string $version): void
    {
        self::assertSame(['profile' => $profile, 'version' => $version], AgentHeader::parse($header));
        self::assertSame($profile, AgentHeader::profile($header));
    }
}
