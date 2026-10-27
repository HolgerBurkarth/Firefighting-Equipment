<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Findet eine bash für Tests der Shell-Skripte (Tests werden sonst übersprungen).
 * Unter Windows nur Git Bash, nie die WSL-bash aus System32.
 */
final class Bash
{
    private static ?string $path = null;
    private static bool $searched = false;

    public static function path(): ?string
    {
        if (!self::$searched) {
            self::$searched = true;
            $candidates = PHP_OS_FAMILY === 'Windows'
                ? array_map(
                    static fn (string $dir): string => $dir . '\\Git\\bin\\bash.exe',
                    array_filter([getenv('ProgramW6432') ?: null, getenv('ProgramFiles') ?: null, 'C:\\Program Files']),
                )
                : ['/bin/bash', '/usr/bin/bash'];
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
     * Pfad in einer Form, die bash auf allen Systemen versteht (unter Windows mit /).
     */
    public static function toPath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * @param list<string>               $args
     * @param string|null                $cwd Arbeitsordner (Standard: der aktuelle)
     * @param array<string, string>|null $env zusätzliche Umgebungsvariablen
     *
     * @return array{int, string, string} Exit-Code, stdout, stderr
     */
    public static function run(string $script, array $args, ?string $cwd = null, ?array $env = null): array
    {
        $bash = self::path() ?? throw new \RuntimeException('bash nicht gefunden.');
        $process = proc_open(
            [$bash, self::toPath($script), ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $env === null ? null : [...getenv(), ...$env],
        );
        if ($process === false) {
            throw new \RuntimeException('bash konnte nicht gestartet werden.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
