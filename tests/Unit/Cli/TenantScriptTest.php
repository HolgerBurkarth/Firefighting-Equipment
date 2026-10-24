<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet bin/tenant.php als eigenen Prozess (ohne Datenbank).
 */
#[CoversNothing]
final class TenantScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/tenant.php';

    public function testWithoutArgumentsExitCodeIs2(): void
    {
        [$exitCode, , $stderr] = self::runScript([]);

        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Kein Befehl angegeben.', $stderr);
    }

    public function testMissingConfigExitCodeIs1(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['list', '--config=gibt-es-nicht.php']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden oder nicht lesbar: gibt-es-nicht.php', $stderr);
    }

    public function testScriptRefusesNonCliSapi(): void
    {
        self::assertStringContainsString("PHP_SAPI !== 'cli'", (string) file_get_contents(self::SCRIPT));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function runScript(array $args): array
    {
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            sys_get_temp_dir(),
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
