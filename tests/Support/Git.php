<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * git für Tests der Shell-Skripte: eigene, leere Konfiguration (Einstellungen des Rechners wie
 * Signieren oder autocrlf gelten nicht) und feste Identität.
 */
final readonly class Git
{
    /** @var array<string, string> */
    public array $env;

    /**
     * @param string $configFile leere Datei, dient als globale Git-Konfiguration
     */
    public function __construct(string $configFile)
    {
        if (!is_file($configFile)) {
            file_put_contents($configFile, '');
        }
        $this->env = [
            'GIT_CONFIG_GLOBAL' => Bash::toPath($configFile),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.org',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.org',
        ];
    }

    /**
     * Führt git aus und liefert stdout ohne abschließenden Zeilenumbruch.
     *
     * @throws \RuntimeException bei einem Exit-Code ungleich 0 (mit stderr)
     */
    public function run(string $cwd, string ...$args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, [...getenv(), ...$this->env]);
        if ($process === false) {
            throw new \RuntimeException('git konnte nicht gestartet werden.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException(sprintf('git %s: %s', implode(' ', $args), $stderr));
        }

        return rtrim($stdout, "\n");
    }
}
