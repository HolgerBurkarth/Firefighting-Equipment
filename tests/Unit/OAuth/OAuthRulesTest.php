<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\OAuth;

use FirefightingEquipment\Auth\Base64Url;
use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\OAuth\ClientRegistration;
use FirefightingEquipment\OAuth\OAuthError;
use FirefightingEquipment\OAuth\OAuthSecret;
use FirefightingEquipment\OAuth\OAuthSecretKind;
use FirefightingEquipment\OAuth\Pkce;
use FirefightingEquipment\OAuth\RedirectUriRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Regeln des OAuth-Servers ohne Datenbank: Rücksprung-Adressen, PKCE S256, Geheimnisse mit Präfix,
 * Angaben der Registrierung und der wirksame Zugang.
 */
#[CoversClass(RedirectUriRules::class)]
#[CoversClass(Pkce::class)]
#[CoversClass(OAuthSecret::class)]
#[CoversClass(ClientRegistration::class)]
#[CoversClass(OAuthError::class)]
#[CoversClass(ClaudeAccess::class)]
#[UsesClass(Base64Url::class)]
final class OAuthRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function redirectUris(): iterable
    {
        yield 'claude.ai' => ['https://claude.ai/api/mcp/auth_callback', true];
        yield 'claude.com' => ['https://claude.com/api/mcp/auth_callback', true];
        yield 'localhost ohne Port' => ['http://localhost/callback', true];
        yield 'localhost mit Port' => ['http://localhost:33418/callback', true];
        yield '127.0.0.1' => ['http://127.0.0.1:8080/callback', true];
        yield 'höchster Port' => ['http://localhost:65535/callback', true];
        yield 'Port zu groß' => ['http://localhost:65536/callback', false];
        yield 'Port 0' => ['http://localhost:0/callback', false];
        yield 'führende Null' => ['http://localhost:08080/callback', false];
        yield 'Präfix von claude.ai' => ['https://claude.ai/api/mcp/auth_callback/x', false];
        yield 'Query' => ['https://claude.ai/api/mcp/auth_callback?x=1', false];
        yield 'Fragment' => ['https://claude.ai/api/mcp/auth_callback#x', false];
        yield 'http statt https' => ['http://claude.ai/api/mcp/auth_callback', false];
        yield 'fremde Subdomain' => ['https://evil.claude.ai/api/mcp/auth_callback', false];
        yield 'Benutzerangaben' => ['https://claude.ai@evil.example/api/mcp/auth_callback', false];
        yield 'Loopback https' => ['https://localhost/callback', false];
        yield 'Loopback anderer Pfad' => ['http://localhost/callback/x', false];
        yield 'Loopback mit Benutzer' => ['http://evil@localhost/callback', false];
        yield 'IPv6' => ['http://[::1]/callback', false];
        yield 'Zeilenende' => ["http://localhost/callback\n", false];
        yield 'Großschreibung' => ['https://Claude.ai/api/mcp/auth_callback', false];
    }

    #[DataProvider('redirectUris')]
    public function testRedirectUriRules(string $uri, bool $allowed): void
    {
        self::assertSame($allowed, RedirectUriRules::isAllowed($uri));
    }

    public function testTargetAndRedirectWithParams(): void
    {
        self::assertSame(['host' => 'claude.ai', 'local' => false], RedirectUriRules::target('https://claude.ai/api/mcp/auth_callback'));
        self::assertSame(['host' => 'localhost', 'local' => true], RedirectUriRules::target('http://localhost:3000/callback'));
        self::assertSame('Claude Code auf dem eigenen Rechner (localhost)', RedirectUriRules::targetLabel('http://localhost:3000/callback'));
        self::assertSame(
            'https://claude.ai/api/mcp/auth_callback?code=ffc_x&state=a%20b%26c%3D%2B',
            RedirectUriRules::withParams('https://claude.ai/api/mcp/auth_callback', ['code' => 'ffc_x', 'state' => 'a b&c=+']),
        );
    }

    public function testPkceS256(): void
    {
        // Beispiel aus RFC 7636, Anhang B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        self::assertTrue(Pkce::isValidChallenge($challenge));
        self::assertTrue(Pkce::verify($verifier, $challenge));
        self::assertFalse(Pkce::verify($verifier . 'x', $challenge));
        self::assertFalse(Pkce::verify($challenge, $challenge), 'plain: Verifier = Challenge');
        self::assertFalse(Pkce::verify('kurz', $challenge));
        self::assertFalse(Pkce::verify(str_repeat('a', 129), $challenge));
        self::assertFalse(Pkce::isValidChallenge(substr($challenge, 1)));
        self::assertFalse(Pkce::isValidChallenge($challenge . '='));
    }

    public function testSecretsHavePrefixAndCanonicalForm(): void
    {
        $secret = OAuthSecret::generate(OAuthSecretKind::AccessToken);
        $value = $secret->value();

        self::assertMatchesRegularExpression('/^ffa_[A-Za-z0-9_-]{43}\z/', $value);
        self::assertSame($secret->hash(), OAuthSecret::parse(OAuthSecretKind::AccessToken, $value)?->hash());
        self::assertSame(32, strlen($secret->hash()));
        self::assertNull(OAuthSecret::parse(OAuthSecretKind::RefreshToken, $value), 'andere Art');
        self::assertNull(OAuthSecret::parse(OAuthSecretKind::AccessToken, substr($value, 4)), 'ohne Präfix');
        self::assertNull(OAuthSecret::parse(OAuthSecretKind::AccessToken, $value . 'A'));
        self::assertStringNotContainsString(substr($value, 4), print_r($secret, true), 'nie im Klartext in Ausgaben');
    }

    public function testRegistrationAcceptsClaude(): void
    {
        // Wie Claude sich in der Vorklärung registriert hat (LIVETEST-MCP.md).
        $registration = ClientRegistration::fromMetadata([
            'client_name' => ' Claude ',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => 'read write',
        ]);

        self::assertSame('Claude', $registration->clientName);
        self::assertSame(['https://claude.ai/api/mcp/auth_callback'], $registration->redirectUris);
        self::assertNull(ClientRegistration::fromMetadata(['redirect_uris' => ['http://localhost/callback'], 'client_name' => "a\nb"])->clientName);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRegistrations(): iterable
    {
        $uris = ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];
        yield 'ohne Adressen' => [[], 'invalid_redirect_uri'];
        yield 'leere Liste' => [['redirect_uris' => []], 'invalid_redirect_uri'];
        yield 'fremde Adresse' => [['redirect_uris' => ['https://evil.example/cb']], 'invalid_redirect_uri'];
        yield 'eine von zwei fremd' => [['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'https://evil.example/cb']], 'invalid_redirect_uri'];
        yield 'sechs Adressen' => [['redirect_uris' => array_fill(0, 6, 'http://localhost/callback')], 'invalid_redirect_uri'];
        yield 'kein Text' => [['redirect_uris' => [42]], 'invalid_redirect_uri'];
        yield 'Client-Geheimnis' => [[...$uris, 'token_endpoint_auth_method' => 'client_secret_post'], 'invalid_client_metadata'];
        yield 'implicit' => [[...$uris, 'grant_types' => ['implicit']], 'invalid_client_metadata'];
        yield 'nur refresh' => [[...$uris, 'grant_types' => ['refresh_token']], 'invalid_client_metadata'];
        yield 'token' => [[...$uris, 'response_types' => ['token']], 'invalid_client_metadata'];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrations(array $metadata, string $error): void
    {
        try {
            ClientRegistration::fromMetadata($metadata);
            self::fail('Erwartet: ' . $error);
        } catch (OAuthError $e) {
            self::assertSame($error, $e->error);
            self::assertSame(400, $e->status);
            $body = json_decode($e->toResponse()->body(), true);
            self::assertSame(['error', 'error_description'], array_keys($body));
        }
    }

    public function testEffectiveAccessIsTheMinimum(): void
    {
        self::assertSame(ClaudeAccess::Read, ClaudeAccess::Write->min(ClaudeAccess::Read));
        self::assertSame(ClaudeAccess::Read, ClaudeAccess::Read->min(ClaudeAccess::Write));
        self::assertSame(ClaudeAccess::Write, ClaudeAccess::Write->min(ClaudeAccess::Write));
        self::assertSame(ClaudeAccess::Off, ClaudeAccess::Write->min(ClaudeAccess::Off));
        self::assertSame(ClaudeAccess::Off, ClaudeAccess::Off->min(ClaudeAccess::Read));
    }
}
