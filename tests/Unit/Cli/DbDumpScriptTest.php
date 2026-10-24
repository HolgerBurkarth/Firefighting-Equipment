<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet bin/db-dump.php als eigenen Prozess (ohne Datenbank).
 */
#[CoversNothing]
final class DbDumpScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/db-dump.php';

    public function testUnknownOptionExitCodeIs2(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--force']);

        self::assertSame(2, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('Unbekannte Option: --force', $stderr);
    }

    public function testMissingTargetDirExitCodeIs1(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--target-dir=gibt-es-nicht-ordner']);

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FEHLER: Sicherungsordner existiert nicht: gibt-es-nicht-ordner', $stderr);
    }

    public function testDefaultTargetDirIsBackupFolderNextToProject(): void
    {
        self::assertStringContainsString("dirname(__DIR__, 2) . '/_backup'", (string) file_get_contents(self::SCRIPT));
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
