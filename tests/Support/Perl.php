<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Findet Perl für Tests der Perl-Skripte (Tests werden sonst übersprungen). Unter Windows das Perl
 * von Git for Windows, sonst /usr/bin/perl wie auf dem Server.
 */
final class Perl
{
    private static ?string $path = null;
    private static bool $searched = false;

    public static function path(): ?string
    {
        if (!self::$searched) {
            self::$searched = true;
            $candidates = PHP_OS_FAMILY === 'Windows'
                ? array_map(
                    static fn (string $dir): string => $dir . '\\Git\\usr\\bin\\perl.exe',
                    array_filter([getenv('ProgramW6432') ?: null, getenv('ProgramFiles') ?: null, 'C:\\Program Files']),
                )
                : ['/usr/bin/perl'];
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    self::$path = $candidate;
                    break;
                }
            }
        }

        return self::$path;
    }

    /**
     * Startet ein Perl-Skript mit genau dieser Umgebung (dazu nur, was das System zum Starten
     * braucht) und dieser Standardeingabe.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string} Exit-Code, stdout, stderr
     */
    public static function run(string $script, array $args = [], array $env = [], string $stdin = ''): array
    {
        $perl = self::path() ?? throw new \RuntimeException('Perl nicht gefunden.');
        $system = PHP_OS_FAMILY === 'Windows'
            ? ['SYSTEMROOT' => (string) getenv('SYSTEMROOT'), 'PATH' => (string) getenv('PATH')]
            : ['PATH' => '/usr/bin:/bin'];
        $process = proc_open(
            [$perl, Bash::toPath($script), ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [...$system, ...$env],
        );
        if ($process === false) {
            throw new \RuntimeException('Perl konnte nicht gestartet werden.');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
