<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionToken;
use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionCookie::class)]
#[UsesClass(AppPath::class)]
#[UsesClass(Request::class)]
#[UsesClass(SessionToken::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class SessionCookieTest extends TestCase
{
    public function testIssueUnderTenantHasAllAttributes(): void
    {
        $token = SessionToken::generate();

        self::assertSame(
            'ff_session=' . $token->cookieValue()
            . '; Path=/firefighting/immendingen/; Max-Age=2592000; HttpOnly; Secure; SameSite=Strict',
            SessionCookie::issue(self::tenant(), $token, 2592000),
        );
    }

    public function testIssueUnderServiceUsesServicePath(): void
    {
        $header = SessionCookie::issue(null, SessionToken::generate(), 43200);

        self::assertStringContainsString('; Path=/firefighting/service/;', $header);
        self::assertStringEndsWith('; Max-Age=43200; HttpOnly; Secure; SameSite=Strict', $header);
    }

    public function testClear(): void
    {
        self::assertSame(
            'ff_session=; Path=/firefighting/service/; Max-Age=0; HttpOnly; Secure; SameSite=Strict',
            SessionCookie::clear(null),
        );
        self::assertSame('/firefighting/immendingen/', SessionCookie::path(self::tenant()));
    }

    public function testTokenFromRequest(): void
    {
        $token = SessionToken::generate();
        $request = new Request('GET', '/', cookies: ['ff_session' => $token->cookieValue(), 'other' => 'x']);

        self::assertSame($token->hash(), SessionCookie::tokenFrom($request)?->hash());
        self::assertTrue(SessionCookie::isPresent($request));
    }

    public function testMissingOrInvalidCookie(): void
    {
        self::assertNull(SessionCookie::tokenFrom(new Request('GET', '/')));
        self::assertFalse(SessionCookie::isPresent(new Request('GET', '/')));

        $invalid = new Request('GET', '/', cookies: ['ff_session' => 'kaputt']);
        self::assertNull(SessionCookie::tokenFrom($invalid));
        self::assertTrue(SessionCookie::isPresent($invalid));
    }

    private static function tenant(): Tenant
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        return new Tenant(1, TenantSlug::fromString('immendingen'), TenantName::fromString('Feuerwehr Immendingen'), true, $now, $now);
    }
}
