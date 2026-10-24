<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

use FirefightingEquipment\Database\DatabaseError;

/**
 * Führt Migrationen aus dem Migrationsordner aus. Nur vorwärts, keine Down-Migrationen.
 */
final readonly class Migrator
{
    private MigrationLog $log;

    public function __construct(
        private \PDO $pdo,
        private string $directory,
        string $table = MigrationLog::DEFAULT_TABLE,
    ) {
        $this->log = new MigrationLog($pdo, $table);
    }

    /**
     * Rein lesend: legt auch die Protokolltabelle nicht an.
     *
     * @throws MigrationException
     */
    public function status(): MigrationStatus
    {
        $files = new MigrationDirectory($this->directory)->load();
        $tableExists = $this->log->exists();
        $applied = $tableExists ? $this->log->applied() : [];

        return new MigrationStatus(
            $this->log->table,
            $tableExists,
            $applied,
            MigrationVerifier::pending($files, $applied),
        );
    }

    /**
     * Führt alle offenen Migrationen einzeln aus; jede wird erst nach Erfolg protokolliert.
     *
     * @param (\Closure(MigrationFile, int): void)|null $onApplied erhält Datei und Dauer in ms
     *
     * @return int Anzahl ausgeführter Migrationen
     *
     * @throws MigrationException
     */
    public function run(?\Closure $onApplied = null): int
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock = new MigrationLock($this->pdo, MigrationLock::nameFor($database, $this->log->table));
        $lock->acquire();

        try {
            $this->log->create();
            // Erst nach dem Sperren prüfen, damit kein paralleler Lauf dazwischenkommt.
            $pending = $this->status()->pending;

            foreach ($pending as $file) {
                $durationMs = $this->apply($file);
                if ($onApplied !== null) {
                    $onApplied($file, $durationMs);
                }
            }

            return count($pending);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return int Dauer in ms
     *
     * @throws MigrationException
     */
    private function apply(MigrationFile $file): int
    {
        $start = hrtime(true);
        $count = count($file->statements);

        foreach ($file->statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (\PDOException $e) {
                throw new MigrationException(self::failureMessage($file, $index + 1, $count, $statement, $e));
            }
        }

        $durationMs = intdiv(hrtime(true) - $start, 1_000_000);

        try {
            $this->log->record($file, $durationMs);
        } catch (\PDOException $e) {
            throw new MigrationException(sprintf(
                'Migration %s wurde ausgeführt, konnte aber nicht protokolliert werden (%s).'
                . ' Datenbank prüfen, bevor erneut migriert wird.',
                $file->filename,
                DatabaseError::describe($e),
            ));
        }

        return $durationMs;
    }

    private static function failureMessage(
        MigrationFile $file,
        int $number,
        int $count,
        string $statement,
        \PDOException $e,
    ): string {
        // Führende Kommentare weglassen, damit die Vorschau mit dem SQL beginnt.
        $preview = preg_replace('/\A(?:\s*(?:(?:--\s|#)[^\n]*|\/\*(?![!M]).*?\*\/))*\s*/s', '', $statement) ?? '';
        $preview = preg_replace('/\s+/', ' ', $preview) ?? '';
        if (mb_strlen($preview) > 80) {
            $preview = mb_substr($preview, 0, 77) . '...';
        }

        $message = sprintf(
            "Migration %s fehlgeschlagen bei Anweisung %d von %d: %s\nAnweisung: %s",
            $file->filename,
            $number,
            $count,
            DatabaseError::describe($e),
            $preview,
        );

        if ($number > 1) {
            $message .= sprintf(
                "\nAnweisung 1%s dieser Migration wurde bereits ausgeführt und nicht zurückgenommen"
                . ' (MariaDB nimmt DDL nicht zurück). Die Migration ist nicht protokolliert;'
                . ' Datenbank prüfen und von Hand bereinigen, bevor erneut migriert wird.',
                $number > 2 ? ' bis ' . ($number - 1) : '',
            );
        }

        return $message . "\nNachfolgende Migrationen wurden nicht ausgeführt.";
    }
}
