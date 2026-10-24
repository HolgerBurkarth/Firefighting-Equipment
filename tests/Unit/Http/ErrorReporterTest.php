<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Http\ErrorReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ErrorReporter::class)]
#[UsesClass(DatabaseError::class)]
final class ErrorReporterTest extends TestCase
{
    public function testReportWritesOneMaskedLineWithCauses(): void
    {
        $lines = [];
        $reporter = new ErrorReporter(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $cause = new \RuntimeException("Access denied for user 'geheim'@'db.example'");
        $reporter->report('health', new \LogicException('Außen', 0, $cause));

        self::assertCount(1, $lines);
        self::assertStringStartsWith('firefighting: health: LogicException: Außen (ErrorReporterTest.php:', $lines[0]);
        self::assertStringContainsString("<- RuntimeException: Access denied for user '***'@'***'", $lines[0]);
        self::assertStringNotContainsString('geheim', $lines[0]);
        self::assertStringNotContainsString('db.example', $lines[0]);
    }

    public function testPdoExceptionIsDescribedWithoutCredentials(): void
    {
        $lines = [];
        $reporter = new ErrorReporter(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $e = new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'geheim'@'localhost'");
        $e->errorInfo = ['HY000', 1045, "Access denied for user 'geheim'@'localhost' (using password: YES)"];
        $reporter->report('db', $e);

        self::assertStringContainsString('SQLSTATE HY000, Fehler 1045', $lines[0]);
        self::assertStringNotContainsString('geheim', $lines[0]);
    }
}
