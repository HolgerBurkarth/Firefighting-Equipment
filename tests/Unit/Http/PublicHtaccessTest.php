<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * public/.htaccess enthält nur die Freigabe (wie vor Runde 5). Die Versuche, Authorization dort an
 * PHP weiterzugeben (SetEnvIf, RewriteRule, CGIPassAuth), wirkten auf STRATO nicht und sind wieder
 * entfernt; das Token kommt über den Vermittler im cgi-bin (CLAUDE.md "Server und .htaccess",
 * "Vermittler für Claude (cgi-bin)"). Weitere Regeln nur nach Rückfrage.
 */
#[CoversNothing]
final class PublicHtaccessTest extends TestCase
{
    private const string FILE = __DIR__ . '/../../../public/.htaccess';

    public function testOnlyTheGrantIsThere(): void
    {
        $content = file_get_contents(self::FILE);
        self::assertIsString($content);
        $directives = array_values(array_filter(
            array_map('trim', explode("\n", str_replace("\r\n", "\n", $content))),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));

        self::assertSame([
            '<IfModule mod_authz_core.c>',
            'Require all granted',
            '</IfModule>',
            '<IfModule !mod_authz_core.c>',
            'Order allow,deny',
            'Allow from all',
            '</IfModule>',
        ], $directives);
    }
}
