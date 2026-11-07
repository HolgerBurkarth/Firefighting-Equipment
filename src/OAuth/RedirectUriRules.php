<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * Erlaubte Rücksprung-Adressen (docs/MCP-KONZEPT.md, Abschnitt 4; Plan, Abschnitt 9a): genau die
 * Rückrufe von claude.ai bzw. claude.com und Loopback für Claude Code (http://localhost bzw.
 * http://127.0.0.1, Port 1-65535 ohne führende Null, Pfad /callback). Kein Query, kein Fragment,
 * keine Benutzerangaben. Eine Anfrage muss danach Byte für Byte eine registrierte Adresse nennen
 * (keine Präfixe, auch kein anderer Port).
 */
final class RedirectUriRules
{
    public const array CLAUDE_REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    public const int MAX_LENGTH = 200;

    private const string LOOPBACK_PATTERN = '#^http://(localhost|127\.0\.0\.1)(?::([1-9][0-9]{0,4}))?/callback\z#';

    public static function isAllowed(string $uri): bool
    {
        if (in_array($uri, self::CLAUDE_REDIRECT_URIS, true)) {
            return true;
        }
        if (preg_match(self::LOOPBACK_PATTERN, $uri, $m) !== 1) {
            return false;
        }

        return ($m[2] ?? '') === '' || (int) $m[2] <= 65535;
    }

    /**
     * Wohin der Zugang geht, für die Seite „Claude verbinden“, die Mail und das Profil.
     *
     * @return array{host: string, local: bool} local = Claude Code auf dem eigenen Rechner (Loopback)
     */
    public static function target(string $uri): array
    {
        $host = parse_url($uri, PHP_URL_HOST);
        $host = is_string($host) ? $host : '';

        return ['host' => $host, 'local' => $host === 'localhost' || $host === '127.0.0.1'];
    }

    /**
     * Bezeichnung des Ziels in Mails: „claude.ai“ bzw. „Claude Code auf dem eigenen Rechner (localhost)“.
     */
    public static function targetLabel(string $uri): string
    {
        $target = self::target($uri);

        return $target['local'] ? sprintf('Claude Code auf dem eigenen Rechner (%s)', $target['host']) : $target['host'];
    }

    /**
     * Rücksprung mit Parametern (die Adresse hat nach den Regeln oben keinen Query).
     *
     * @param array<string, string> $params
     */
    public static function withParams(string $uri, array $params): string
    {
        return $uri . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
