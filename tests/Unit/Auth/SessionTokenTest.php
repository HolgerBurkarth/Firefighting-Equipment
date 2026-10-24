<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\SessionToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionToken::class)]
final class SessionTokenTest extends TestCase
{
    public function testGeneratedTokenIsBase64UrlWith43Characters(): void
    {
        $token = SessionToken::generate();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $token->cookieValue());
        self::assertSame(32, strlen($token->hash()));
    }

    public function testTokensAreRandom(): void
    {
        self::assertNotSame(SessionToken::generate()->cookieValue(), SessionToken::generate()->cookieValue());
    }

    public function testRoundTripKeepsHash(): void
    {
        $token = SessionToken::generate();
        $parsed = SessionToken::fromCookie($token->cookieValue());

        self::assertNotNull($parsed);
        self::assertSame($token->hash(), $parsed->hash());
    }

    public function testHashIsSha256OfRawBytes(): void
    {
        $value = rtrim(strtr(base64_encode(str_repeat("\x01", 32)), '+/', '-_'), '=');

        self::assertSame(hash('sha256', str_repeat("\x01", 32), true), SessionToken::fromCookie($value)?->hash());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'leer' => [''];
        yield 'zu kurz' => [str_repeat('A', 42)];
        yield 'zu lang' => [str_repeat('A', 44)];
        yield 'Auffüllung' => [str_repeat('A', 42) . '='];
        yield 'Standard-Base64' => [str_repeat('A', 42) . '+'];
        yield 'nicht kanonisch' => [str_repeat('A', 42) . 'B'];
        yield 'Zeilenumbruch' => [str_repeat('A', 43) . "\n"];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(string $value): void
    {
        self::assertNull(SessionToken::fromCookie($value));
    }

    public function testDebugOutputHidesToken(): void
    {
        $token = SessionToken::generate();

        self::assertStringNotContainsString($token->cookieValue(), print_r($token, true));
    }
}
