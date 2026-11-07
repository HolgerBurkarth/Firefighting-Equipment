<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * public/.htaccess: die Freigabe bleibt, dazu genau die Regel, die Authorization an PHP weitergibt
 * (STRATO, PHP als CGI); FF_AUTH_SEEN nur mit dem festen Wert 1. Kein CGIPassAuth (wirkte live
 * nicht, 07.11.2026). Weitere Regeln nur nach Rückfrage (CLAUDE.md "Server und .htaccess").
 */
#[CoversNothing]
final class PublicHtaccessTest extends TestCase
{
    private const string FILE = __DIR__ . '/../../../public/.htaccess';

    private const string GRANT = "<IfModule mod_authz_core.c>\n    Require all granted\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Allow from all\n</IfModule>\n";

    private const string REWRITE = "<IfModule mod_rewrite.c>\n"
        . "    RewriteEngine On\n"
        . "    RewriteCond %{HTTP:Authorization} .\n"
        . "    RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},E=FF_AUTH_SEEN:1]\n"
        . "</IfModule>\n";

    public function testGrantIsKept(): void
    {
        self::assertStringContainsString(self::GRANT, $this->content());
    }

    public function testOnlyTheAuthorizationRuleIsAdded(): void
    {
        $directives = array_values(array_filter(
            array_map('trim', explode("\n", $this->content())),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));

        self::assertStringContainsString(self::REWRITE, $this->content());
        self::assertSame([
            '<IfModule mod_authz_core.c>',
            'Require all granted',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            'Order allow,deny',
            'Allow from all',
            '</IfModule>',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{HTTP:Authorization} .',
            'RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},E=FF_AUTH_SEEN:1]',
            '</IfModule>',
        ], $directives);
    }

    /** Zeilenenden wie im Arbeitsbaum (unter Windows CRLF), verglichen wird mit LF. */
    private function content(): string
    {
        $content = file_get_contents(self::FILE);
        self::assertIsString($content);

        return str_replace("\r\n", "\n", $content);
    }
}
