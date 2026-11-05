<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\RoleAssignmentCheckCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * bin/role-assignment-check.php als eigener Prozess, ohne Datenbank.
 */
#[CoversClass(RoleAssignmentCheckCommand::class)]
final class RoleAssignmentCheckScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/role-assignment-check.php';

    public function testHelp(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--help']);

        self::assertSame(RoleAssignmentCheckCommand::EXIT_OK, $exitCode, $stderr);
        self::assertStringContainsString('php bin/role-assignment-check.php [--config=PFAD]', $stdout);
        self::assertStringContainsString('Ändert nichts.', $stdout);
    }

    public function testMissingConfigExitCodeIs1(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--config=gibt-es-nicht.php']);

        self::assertSame(RoleAssignmentCheckCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden oder nicht lesbar: gibt-es-nicht.php', $stderr);
    }

    public function testUnknownArgumentExitCodeIs2(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--execute']);

        self::assertSame(RoleAssignmentCheckCommand::EXIT_USAGE, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FEHLER: Unerwartetes Argument: --execute', $stderr);
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
