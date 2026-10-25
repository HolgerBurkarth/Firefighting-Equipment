<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Startet tests/Support/fake-smtp-server.php mit einem Szenario (Schritte siehe dort) und liefert
 * Port und Protokoll der empfangenen Zeilen. Kein echter Mailserver, nur 127.0.0.1.
 */
final class FakeSmtpServer
{
    /** Letzter Eintrag im Protokoll, wenn das Skript fertig ist. */
    private const string END_MARKER = '<ende>';

    public readonly int $port;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private function __construct(private readonly TempDirectory $dir)
    {
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param string|null                $certFile PEM mit Zertifikat und Schlüssel (für Schritt "tls")
     */
    public static function start(array $steps, ?string $certFile = null): self
    {
        $server = new self(new TempDirectory());
        $server->dir->write('scenario.json', json_encode($steps, JSON_THROW_ON_ERROR));
        $server->dir->write('log.jsonl', '');

        $command = [PHP_BINARY, __DIR__ . '/fake-smtp-server.php', $server->dir->file('scenario.json'), $server->dir->file('log.jsonl')];
        if ($certFile !== null) {
            $command[] = $certFile;
        }
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', $server->dir->file('stderr.txt'), 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Fake-SMTP-Server konnte nicht gestartet werden.');
        }
        fclose($pipes[0]);
        $server->process = $process;
        $server->stdout = $pipes[1];

        $line = (string) fgets($pipes[1]);
        if (preg_match('/^PORT (\d+)$/', trim($line), $match) !== 1) {
            throw new \RuntimeException('Fake-SMTP-Server meldet keinen Port: ' . $line . (string) file_get_contents($server->dir->file('stderr.txt')));
        }
        $server->port = (int) $match[1];

        return $server;
    }

    /**
     * Wartet auf das Ende des Servers und liefert die empfangenen Zeilen bzw. Daten.
     *
     * @return list<string>
     */
    public function received(): array
    {
        $this->stop();
        $entries = $this->entries();
        if (array_pop($entries) !== self::END_MARKER) {
            throw new \RuntimeException('Fake-SMTP-Server nicht regulär beendet: ' . (string) file_get_contents($this->dir->file('stderr.txt')));
        }

        return $entries;
    }

    /**
     * Wartet höchstens $waitSeconds, bis das Skript fertig ist, und beendet dann den Prozess.
     * Mehrfach aufrufbar.
     */
    private function stop(float $waitSeconds = 10.0): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $until = microtime(true) + $waitSeconds;
        while (!in_array(self::END_MARKER, $this->entries(), true) && proc_get_status($this->process)['running'] && microtime(true) < $until) {
            usleep(5_000);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }
        fclose($this->stdout);
        proc_close($this->process);
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        $entries = [];
        foreach (file($this->dir->file('log.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            // Eine gerade erst geschriebene Zeile kann noch unvollständig sein.
            $entry = json_decode($line);
            if (is_string($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Beendet den Server sofort (falls noch nicht geschehen) und löscht seine Dateien.
     */
    public function remove(): void
    {
        $this->stop(0.0);
        $this->dir->remove();
    }
}
