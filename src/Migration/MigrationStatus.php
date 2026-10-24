<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Stand der Migrationen: ausgeführte und offene.
 */
final readonly class MigrationStatus
{
    /**
     * @param list<AppliedMigration> $applied
     * @param list<MigrationFile> $pending
     */
    public function __construct(
        public string $table,
        public bool $tableExists,
        public array $applied,
        public array $pending,
    ) {
    }
}
