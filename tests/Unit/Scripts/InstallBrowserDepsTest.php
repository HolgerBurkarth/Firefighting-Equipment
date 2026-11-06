<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Scripts;

use FirefightingEquipment\Tests\Support\Bash;
use FirefightingEquipment\Tests\Support\TempDirectory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Startet scripts/install-browser-deps.sh mit bash, mit einem Ersatz für den Befehl, für sudo und
 * für die Paketquellen (übersprungen, wenn keine bash vorhanden ist).
 */
#[CoversNothing]
final class InstallBrowserDepsTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../scripts/install-browser-deps.sh';
    private const string SOURCES = "Types: deb\nURIs: http://azure.archive.ubuntu.com/ubuntu/\nSuites: noble noble-updates\n";

    private TempDirectory $dir;

    protected function setUp(): void
    {
        if (Bash::path() === null) {
            self::markTestSkipped('bash nicht gefunden.');
        }
        $this->dir = new TempDirectory();
        $this->dir->write('ubuntu.sources', self::SOURCES);
        // Ersatz für sudo: protokolliert den Aufruf, führt sed aus, dpkg nicht.
        $this->dir->write('sudo.sh', "#!/usr/bin/env bash\n"
            . 'echo "$*" >> ' . escapeshellarg(Bash::toPath($this->dir->file('sudo.log'))) . "\n"
            . "[ \"\$1\" = dpkg ] && exit 0\n"
            . "exec \"\$@\"\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $this->dir->remove();
        }
    }

    public function testSuccessOnFirstAttemptChangesNothing(): void
    {
        [$exitCode, $stdout, $stderr] = $this->install([0]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame(1, $this->calls());
        self::assertStringContainsString('Versuch 1 von 3 (höchstens 5 s)', $stdout);
        self::assertStringNotContainsString('::warning::', $stdout);
        self::assertFileDoesNotExist($this->dir->file('sudo.log'));
        self::assertSame(self::SOURCES, $this->sources());
    }

    public function testRetriesWithFallbackMirrorAfterFailure(): void
    {
        [$exitCode, $stdout, $stderr] = $this->install([100, 100, 0]);

        self::assertSame(0, $exitCode, $stderr);
        self::assertSame(3, $this->calls());
        self::assertSame(2, substr_count($stdout, 'endete mit Exit-Code 100.'));
        self::assertStringContainsString('Versuch 3 von 3', $stdout);
        // Umgestellt wird einmal; die zweite Wiederholung findet den Mirror nicht mehr.
        self::assertSame(1, substr_count($stdout, 'Paketquelle umgestellt:'));
        self::assertSame(
            "Types: deb\nURIs: http://archive.ubuntu.com/ubuntu/\nSuites: noble noble-updates\n",
            $this->sources(),
        );
        $log = (string) file_get_contents($this->dir->file('sudo.log'));
        self::assertSame(2, substr_count($log, "dpkg --configure -a\n"));
        self::assertSame(1, substr_count($log, 'sed -i s/azure.archive.ubuntu.com/archive.ubuntu.com/g'));
    }

    public function testEveryAttemptIsLimitedAndAllFailedGivesExitCode1(): void
    {
        [$exitCode, $stdout] = $this->install([], 1);

        self::assertSame(1, $exitCode);
        self::assertSame(3, $this->calls());
        self::assertSame(3, substr_count($stdout, 'nach 1 s abgebrochen.'));
        self::assertStringContainsString('::error::Systempakete der Browser: alle 3 Versuche gescheitert.', $stdout);
    }

    public function testInvalidArgumentsGiveExitCode2(): void
    {
        [$exitCode, , $stderr] = Bash::run(self::SCRIPT, []);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('Aufruf:', $stderr);

        [$exitCode] = Bash::run(self::SCRIPT, ['true'], null, ['FF_BROWSER_DEPS_TIMEOUT' => '0']);
        self::assertSame(2, $exitCode);
    }

    /**
     * @param list<int> $exitCodes Exit-Code des Befehls je Versuch; leer = jeder Versuch hängt
     *
     * @return array{int, string, string}
     */
    private function install(array $exitCodes, int $timeout = 5): array
    {
        $counter = Bash::toPath($this->dir->file('calls'));
        $this->dir->write('deps.sh', "#!/usr/bin/env bash\n"
            . 'n=$(( $(cat ' . escapeshellarg($counter) . ' 2>/dev/null || echo 0) + 1 ))' . "\n"
            . 'echo "$n" > ' . escapeshellarg($counter) . "\n"
            . ($exitCodes === []
                ? "sleep 10\n"
                : 'codes=(' . implode(' ', $exitCodes) . ')' . "\n" . 'exit "${codes[$((n - 1))]}"' . "\n"));

        return Bash::run(self::SCRIPT, ['bash', Bash::toPath($this->dir->file('deps.sh'))], null, [
            'FF_BROWSER_DEPS_TIMEOUT' => (string) $timeout,
            'FF_BROWSER_DEPS_SUDO' => 'bash ' . Bash::toPath($this->dir->file('sudo.sh')),
            'FF_BROWSER_DEPS_SOURCES' => Bash::toPath($this->dir->file('ubuntu.sources'))
                . ' ' . Bash::toPath($this->dir->file('fehlt.list')),
        ]);
    }

    private function calls(): int
    {
        return (int) trim((string) @file_get_contents($this->dir->file('calls')));
    }

    private function sources(): string
    {
        return (string) file_get_contents($this->dir->file('ubuntu.sources'));
    }
}
