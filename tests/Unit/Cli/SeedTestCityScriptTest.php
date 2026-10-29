<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\SeedTestCityCommand;
use FirefightingEquipment\Device\Seed\TestCityData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Aufruf von bin/seed-test-city.php ohne Datenbank: Sperre auf genau das Kürzel "test" vor jedem
 * Verbindungsaufbau, Optionen, Skript als eigener Prozess.
 */
#[CoversClass(SeedTestCityCommand::class)]
#[UsesClass(TestCityData::class)]
final class SeedTestCityScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../bin/seed-test-city.php';

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidCalls(): iterable
    {
        yield 'ohne Kürzel' => [[], '--slug=test fehlt.'];
        yield 'andere Stadt' => [['--slug=immendingen'], 'nur für die Test-Stadt'];
        yield 'Großschreibung' => [['--slug=Test'], 'nur für die Test-Stadt'];
        yield 'Präfix' => [['--slug=test-2'], 'nur für die Test-Stadt'];
        yield 'Leerzeichen' => [['--slug=test '], 'nur für die Test-Stadt'];
        yield 'service' => [['--slug=service'], 'nur für die Test-Stadt'];
        yield 'doppelt' => [['--slug=test', '--slug=test'], '--slug ist doppelt angegeben.'];
        yield 'Anzahl 0' => [['--slug=test', '--devices=0'], '--devices= braucht eine Zahl von 1 bis 5000.'];
        yield 'Anzahl zu groß' => [['--slug=test', '--devices=5001'], '--devices= braucht eine Zahl von 1 bis 5000.'];
        yield 'Anzahl mit führender Null' => [['--slug=test', '--devices=0100'], '--devices= braucht eine Zahl'];
        yield 'Anzahl keine Zahl' => [['--slug=test', '--devices=viele'], '--devices= braucht eine Zahl'];
        yield 'unbekannte Option' => [['--slug=test', '--confirm=test'], 'Unbekannte Option: --confirm=test'];
        yield 'Argument' => [['--slug=test', 'run'], 'Unerwartetes Argument: run'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('invalidCalls')]
    public function testInvalidCallsExitWith2BeforeConnecting(array $args, string $message): void
    {
        // Die Konfiguration gibt es nicht: Ein Verbindungsversuch endete mit Exit-Code 1.
        [$exitCode, $stdout, $stderr] = self::runScript([...$args, '--config=gibt-es-nicht.php']);

        self::assertSame(SeedTestCityCommand::EXIT_USAGE, $exitCode, $stderr);
        self::assertSame('', $stdout);
        self::assertStringContainsString($message, $stderr);
    }

    public function testValidCallsAreParsed(): void
    {
        self::assertSame(
            ['help' => false, 'devices' => null, 'execute' => false, 'config' => null],
            SeedTestCityCommand::parseArguments(['--slug=test']),
        );
        self::assertSame(
            ['help' => false, 'devices' => 2500, 'execute' => true, 'config' => 'x.php'],
            SeedTestCityCommand::parseArguments(['--execute', '--devices=2500', '--slug=test', '--config=x.php']),
        );
        self::assertSame(5000, SeedTestCityCommand::parseArguments(['--slug=test', '--devices=5000'])['devices']);
    }

    public function testSlugIsFixed(): void
    {
        self::assertSame('test', TestCityData::SLUG);
    }

    public function testMissingConfigExitCodeIs1(): void
    {
        [$exitCode, $stdout, $stderr] = self::runScript(['--slug=test', '--config=gibt-es-nicht.php']);

        self::assertSame(SeedTestCityCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('FEHLER: Konfigurationsdatei nicht gefunden oder nicht lesbar: gibt-es-nicht.php', $stderr);
    }

    public function testHelp(): void
    {
        [$exitCode, $stdout] = self::runScript(['--help']);

        self::assertSame(SeedTestCityCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('php bin/seed-test-city.php --slug=test', $stdout);
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
