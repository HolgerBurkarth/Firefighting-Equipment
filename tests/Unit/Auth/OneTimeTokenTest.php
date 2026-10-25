<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\Base64Url;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OneTimeToken::class)]
#[CoversClass(Base64Url::class)]
#[CoversClass(TokenPurpose::class)]
final class OneTimeTokenTest extends TestCase
{
    public function testGeneratedTokenRoundTrips(): void
    {
        $token = OneTimeToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token->value());
        $parsed = OneTimeToken::fromString($token->value());
        self::assertNotNull($parsed);
        self::assertSame($token->hash(), $parsed->hash());
        self::assertSame(32, strlen($token->hash()));
        self::assertNotSame($token->value(), OneTimeToken::generate()->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'leer' => [''];
        yield 'zu kurz' => [str_repeat('A', 42)];
        yield 'zu lang' => [str_repeat('A', 44)];
        yield 'mit Auffüllung' => [str_repeat('A', 43) . '='];
        yield 'Standard-Base64' => [str_repeat('A', 42) . '+'];
        yield 'nicht kanonisch' => [str_repeat('A', 42) . 'B'];
        yield 'Leerzeichen' => [' ' . str_repeat('A', 42)];
        yield 'Zeilenende' => [str_repeat('A', 43) . "\n"];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueIsRejected(string $value): void
    {
        self::assertNull(OneTimeToken::fromString($value));
    }

    public function testDebugOutputHidesToken(): void
    {
        $token = OneTimeToken::generate();

        self::assertStringNotContainsString($token->value(), print_r($token, true));
    }

    public function testLifetimes(): void
    {
        self::assertSame(3600, TokenPurpose::ResetPassword->lifetimeSeconds());
        self::assertSame(86400, TokenPurpose::VerifyEmail->lifetimeSeconds());
        self::assertSame(86400, TokenPurpose::SetPassword->lifetimeSeconds());
    }
}
