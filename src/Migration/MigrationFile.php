<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Eine eingelesene Migrationsdatei.
 */
final readonly class MigrationFile
{
    /**
     * @param list<string> $statements
     */
    public function __construct(
        public int $version,
        public string $filename,
        public string $checksum,
        public array $statements,
    ) {
    }
}
