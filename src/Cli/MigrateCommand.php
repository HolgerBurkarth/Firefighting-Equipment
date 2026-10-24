<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseError;
use FirefightingEquipment\Migration\MigrationException;
use FirefightingEquipment\Migration\MigrationFile;
use FirefightingEquipment\Migration\MigrationLog;
use FirefightingEquipment\Migration\MigrationStatus;
use FirefightingEquipment\Migration\Migrator;

/**
 * Kommandozeile für Migrationen (bin/migrate.php).
 */
final readonly class MigrateCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;
    /** Nur bei check: Es gibt offene Migrationen. */
    public const int EXIT_PENDING = 3;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/migrate.php status [--config=PFAD]           ausgeführte und offene Migrationen
          php bin/migrate.php run [--config=PFAD]              offene Migrationen ausführen
          php bin/migrate.php run --dry-run [--config=PFAD]    nur anzeigen, was passieren würde
          php bin/migrate.php check [--config=PFAD]            nur prüfen, ob Migrationen offen sind

        Exit-Codes: 0 OK (bei check: nichts offen), 1 Fehler, 2 falscher Aufruf,
        3 nur bei check: Migrationen offen.

        Standard-Konfiguration: config/config.local.php
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    /**
     * @param resource $out
     * @param resource $err
     */
    public function __construct(
        private string $migrationsDir,
        private string $defaultConfigPath,
        private mixed $out,
        private mixed $err,
        private string $table = MigrationLog::DEFAULT_TABLE,
    ) {
    }

    /**
     * @param list<string> $args Argumente ohne Skriptnamen
     */
    public function run(array $args): int
    {
        try {
            $options = self::parseArguments($args);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL . PHP_EOL . self::USAGE);

            return self::EXIT_USAGE;
        }

        if ($options['command'] === 'help') {
            fwrite($this->out, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            $config = new ConfigLoader()->loadDatabaseConfig($options['config'] ?? $this->defaultConfigPath);
            $migrator = new Migrator(new ConnectionFactory()->create($config), $this->migrationsDir, $this->table);

            if ($options['command'] === 'check') {
                return $this->check($migrator->status());
            }

            if ($options['command'] === 'status') {
                $this->printStatus($migrator->status());
            } elseif ($options['dryRun']) {
                $this->printDryRun($migrator->status());
            } else {
                $this->migrate($migrator);
            }
        } catch (ConfigException | ConnectionException | MigrationException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);

            return self::EXIT_ERROR;
        } catch (\PDOException $e) {
            fwrite($this->err, 'FEHLER: Datenbankfehler (' . DatabaseError::describe($e) . ').' . PHP_EOL);

            return self::EXIT_ERROR;
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     *
     * @return array{command: 'status'|'run'|'check'|'help', dryRun: bool, config: ?string}
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): array
    {
        $command = null;
        $dryRun = false;
        $config = null;

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return ['command' => 'help', 'dryRun' => false, 'config' => null];
            }

            if ($arg === '--dry-run') {
                if ($dryRun) {
                    throw new \InvalidArgumentException('--dry-run ist doppelt angegeben.');
                }
                $dryRun = true;
            } elseif (str_starts_with($arg, '--config=')) {
                if ($config !== null) {
                    throw new \InvalidArgumentException('--config ist doppelt angegeben.');
                }
                $config = substr($arg, strlen('--config='));
                if ($config === '') {
                    throw new \InvalidArgumentException('--config= braucht einen Pfad.');
                }
            } elseif (str_starts_with($arg, '-')) {
                throw new \InvalidArgumentException(sprintf('Unbekannte Option: %s', $arg));
            } elseif ($command !== null) {
                throw new \InvalidArgumentException(sprintf('Unerwartetes Argument: %s', $arg));
            } elseif ($arg === 'status' || $arg === 'run' || $arg === 'check') {
                $command = $arg;
            } else {
                throw new \InvalidArgumentException(sprintf('Unbekannter Befehl: %s', $arg));
            }
        }

        if ($command === null) {
            throw new \InvalidArgumentException('Kein Befehl angegeben.');
        }
        if ($dryRun && $command !== 'run') {
            throw new \InvalidArgumentException('--dry-run ist nur mit run möglich.');
        }

        return ['command' => $command, 'dryRun' => $dryRun, 'config' => $config];
    }

    /**
     * Rein lesend; Ergebnis für Skripte (z. B. deploy.sh) im Exit-Code.
     */
    private function check(MigrationStatus $status): int
    {
        $count = count($status->pending);
        if ($count === 0) {
            $this->line('Keine offenen Migrationen.');

            return self::EXIT_OK;
        }

        $this->line(sprintf('%d offene Migration(en).', $count));

        return self::EXIT_PENDING;
    }

    private function printStatus(MigrationStatus $status): void
    {
        $this->printTable($status);

        $width = $this->filenameWidth($status);
        foreach ($status->applied as $entry) {
            $this->line(sprintf(
                '[x] %-' . $width . 's  %s UTC  %d ms',
                $entry->filename,
                $entry->appliedAt,
                $entry->durationMs,
            ));
        }
        foreach ($status->pending as $file) {
            $this->line(sprintf('[ ] %-' . $width . 's  offen', $file->filename));
        }

        if ($status->applied === [] && $status->pending === []) {
            $this->line('Keine Migrationen vorhanden.');
        } else {
            $this->line(sprintf('%d ausgeführt, %d offen.', count($status->applied), count($status->pending)));
        }
    }

    private function printDryRun(MigrationStatus $status): void
    {
        $this->line('Trockenlauf: Es wird nichts ausgeführt.');
        $this->printTable($status);

        if ($status->pending === []) {
            $this->line('Keine offenen Migrationen.');

            return;
        }

        if (!$status->tableExists) {
            $this->line(sprintf('Würde Protokolltabelle %s anlegen.', $status->table));
        }
        foreach ($status->pending as $file) {
            $this->line(sprintf('Würde ausführen: %s (%s)', $file->filename, self::statementCount($file)));
        }
        $this->line(sprintf('%d Migration(en) offen.', count($status->pending)));
    }

    /**
     * @throws MigrationException
     */
    private function migrate(Migrator $migrator): void
    {
        $count = $migrator->run(function (MigrationFile $file, int $durationMs): void {
            $this->line(sprintf('OK  %s (%d ms)', $file->filename, $durationMs));
        });

        $this->line($count === 0 ? 'Keine offenen Migrationen.' : sprintf('%d Migration(en) ausgeführt.', $count));
    }

    private function printTable(MigrationStatus $status): void
    {
        $this->line(sprintf(
            'Protokolltabelle: %s%s',
            $status->table,
            $status->tableExists ? '' : ' (noch nicht angelegt)',
        ));
    }

    private function filenameWidth(MigrationStatus $status): int
    {
        $names = [
            ...array_map(static fn ($entry): string => $entry->filename, $status->applied),
            ...array_map(static fn (MigrationFile $file): string => $file->filename, $status->pending),
        ];

        return max([0, ...array_map(strlen(...), $names)]);
    }

    private static function statementCount(MigrationFile $file): string
    {
        $count = count($file->statements);

        return $count === 1 ? '1 Anweisung' : sprintf('%d Anweisungen', $count);
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
