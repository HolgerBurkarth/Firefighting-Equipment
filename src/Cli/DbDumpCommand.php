<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Backup\DatabaseBackup;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Database\DatabaseError;

/**
 * Kommandozeile für Datenbanksicherungen (bin/db-dump.php). Gesichert werden nur Tabellen fe_*.
 *
 * Auf stdout steht bei Erfolg ausschließlich der Pfad der Sicherung (für deploy.sh);
 * alle anderen Meldungen gehen nach stderr. Gibt es keine Tabelle fe_* (z. B. vor der ersten
 * Migration), endet der Aufruf mit EXIT_NOTHING_TO_DUMP, ohne Datei und ohne mysqldump aufzurufen:
 * Ohne Tabellenliste würde mysqldump die ganze Datenbank sichern.
 */
final readonly class DbDumpCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;
    public const int EXIT_NOTHING_TO_DUMP = 4;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/db-dump.php [--config=PFAD] [--target-dir=PFAD] [--suffix=ZUSATZ] [--mysqldump=PROGRAMM]

        Sichert alle Tabellen fe_* nach ZIELORDNER/ff-db-JJJJMMTT-HHMMSSZ[-ZUSATZ].sql.gz (UTC, Rechte 600)
        und behält die 10 neuesten Sicherungen. Gibt bei Erfolg nur den Pfad der Sicherung aus.

          --config=PFAD       Standard: config/config.local.php
          --target-dir=PFAD   Standard: ~/htdocs/apps/_backup; muss existieren und eine .htaccess enthalten
          --suffix=ZUSATZ     a-z, 0-9 und Bindestrich, z. B. vor-migration-81a0d4c
          --mysqldump=PFAD    Programm mysqldump (Standard: mysqldump aus PATH)

        Exit-Codes: 0 OK, 1 Fehler, 2 falscher Aufruf,
                    4 nichts zu sichern (keine Tabellen fe_*; keine Datei angelegt).
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    private const array OPTIONS = ['config', 'target-dir', 'suffix', 'mysqldump'];

    /**
     * @param resource $out
     * @param resource $err
     * @param (\Closure(DatabaseConfig): list<string>)|null $listTables ermittelt die Tabellen fe_*;
     *        Standard: BackupTables::list über eine neue Verbindung (für Tests austauschbar)
     */
    public function __construct(
        private string $defaultConfigPath,
        private string $defaultTargetDir,
        private mixed $out,
        private mixed $err,
        private ?\Closure $listTables = null,
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

        if ($options === null) {
            fwrite($this->out, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            // Zielordner vor dem Verbindungsaufbau prüfen.
            $backup = DatabaseBackup::open(
                $options['target-dir'] ?? $this->defaultTargetDir,
                [$options['mysqldump'] ?? 'mysqldump'],
                $this->listTables,
            );

            $config = new ConfigLoader()->loadDatabaseConfig($options['config'] ?? $this->defaultConfigPath);
            $result = $backup->create($config, $options['suffix']);
            if ($result === null) {
                fwrite($this->err, 'Hinweis: Keine Tabellen fe_* vorhanden – nichts zu sichern, keine Datei angelegt.' . PHP_EOL);

                return self::EXIT_NOTHING_TO_DUMP;
            }

            foreach ($result->deleted as $deleted) {
                fwrite($this->err, sprintf('Lösche alte Sicherung: %s', $deleted) . PHP_EOL);
            }
            $path = $result->path;
        } catch (BackupException | ConfigException | ConnectionException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);

            return self::EXIT_ERROR;
        } catch (\PDOException $e) {
            fwrite($this->err, 'FEHLER: Datenbankfehler (' . DatabaseError::describe($e) . ').' . PHP_EOL);

            return self::EXIT_ERROR;
        }

        fwrite($this->out, $path . PHP_EOL);

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     *
     * @return array{config: ?string, target-dir: ?string, suffix: ?string, mysqldump: ?string}|null
     *         null bei --help
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): ?array
    {
        $options = array_fill_keys(self::OPTIONS, null);

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return null;
            }

            if (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $match) !== 1 || !in_array($match[1], self::OPTIONS, true)) {
                throw new \InvalidArgumentException(str_starts_with($arg, '-')
                    ? sprintf('Unbekannte Option: %s', $arg)
                    : sprintf('Unerwartetes Argument: %s', $arg));
            }

            [, $name, $value] = $match;
            if ($options[$name] !== null) {
                throw new \InvalidArgumentException(sprintf('--%s ist doppelt angegeben.', $name));
            }
            if ($value === '') {
                throw new \InvalidArgumentException(sprintf('--%s= braucht einen Wert.', $name));
            }
            if ($name === 'suffix') {
                BackupFileName::assertValidSuffix($value);
            }
            $options[$name] = $value;
        }

        /** @var array{config: ?string, target-dir: ?string, suffix: ?string, mysqldump: ?string} $options */
        return $options;
    }
}
