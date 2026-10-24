<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

use FirefightingEquipment\Database\ConnectionFactory;

/**
 * Zugriff auf die Protokolltabelle der Migrationen.
 */
final readonly class MigrationLog
{
    public const string DEFAULT_TABLE = 'fe_schema_migrations';

    public function __construct(private \PDO $pdo, public string $table = self::DEFAULT_TABLE)
    {
        self::assertValidTableName($table);
    }

    /**
     * Der Name wird in SQL eingesetzt und ist daher streng beschränkt.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidTableName(string $table): void
    {
        if (preg_match('/^fe_[a-z0-9_]{1,61}$/D', $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Ungültiger Name der Protokolltabelle: %s', $table));
        }
    }

    public function exists(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        );
        $stmt->execute([$this->table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(): void
    {
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` ('
            . ' version SMALLINT UNSIGNED NOT NULL PRIMARY KEY,'
            . ' filename VARCHAR(255) NOT NULL,'
            . ' checksum CHAR(64) NOT NULL,'
            . " applied_at DATETIME(3) NOT NULL COMMENT 'Abschluss der Migration, UTC',"
            . ' duration_ms INT UNSIGNED NOT NULL,'
            . ' UNIQUE KEY uq_filename (filename)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=%s COLLATE=%s',
            $this->table,
            ConnectionFactory::CHARSET,
            ConnectionFactory::COLLATION,
        ));
    }

    /**
     * @return list<AppliedMigration> nach Version sortiert; leer, wenn die Tabelle fehlt
     */
    public function applied(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $rows = $this->pdo->query(sprintf(
            "SELECT version, filename, checksum, DATE_FORMAT(applied_at, '%%Y-%%m-%%d %%H:%%i:%%s.%%f') AS applied_at,"
            . ' duration_ms FROM `%s` ORDER BY version',
            $this->table,
        ))->fetchAll();

        return array_map(static fn (array $row): AppliedMigration => new AppliedMigration(
            (int) $row['version'],
            (string) $row['filename'],
            (string) $row['checksum'],
            // %f liefert Mikrosekunden; gespeichert sind Millisekunden.
            substr((string) $row['applied_at'], 0, 23),
            (int) $row['duration_ms'],
        ), $rows);
    }

    public function record(MigrationFile $file, int $durationMs): void
    {
        $this->pdo->prepare(sprintf(
            'INSERT INTO `%s` (version, filename, checksum, applied_at, duration_ms)'
            . ' VALUES (?, ?, ?, UTC_TIMESTAMP(3), ?)',
            $this->table,
        ))->execute([$file->version, $file->filename, $file->checksum, $durationMs]);
    }
}
