<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Sperre gegen gleichzeitige Migrationsläufe (GET_LOCK, gilt für die Verbindung).
 */
final readonly class MigrationLock
{
    public function __construct(private \PDO $pdo, private string $name)
    {
    }

    /**
     * GET_LOCK gilt serverweit; Datenbank und Tabelle machen den Namen eindeutig.
     * Gekürzt, da MariaDB höchstens 64 Zeichen erlaubt.
     */
    public static function nameFor(string $database, string $table): string
    {
        return 'fe_migrate_' . substr(hash('sha256', $database . '.' . $table), 0, 40);
    }

    /**
     * @throws MigrationException wenn die Sperre bereits belegt ist
     */
    public function acquire(): void
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$this->name]);
        $result = $stmt->fetchColumn();

        if ($result === null) {
            throw new MigrationException('Die Sperre für Migrationen konnte nicht gesetzt werden.');
        }
        if ((int) $result !== 1) {
            throw new MigrationException('Es läuft bereits eine Migration (Sperre belegt). Abbruch.');
        }
    }

    public function release(): void
    {
        $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->name]);
    }
}
