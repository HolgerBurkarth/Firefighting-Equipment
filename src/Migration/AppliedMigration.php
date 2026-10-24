<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Ein Eintrag der Protokolltabelle.
 */
final readonly class AppliedMigration
{
    public function __construct(
        public int $version,
        public string $filename,
        public string $checksum,
        /** Zeitpunkt des Abschlusses in UTC, Format Y-m-d H:i:s.v */
        public string $appliedAt,
        public int $durationMs,
    ) {
    }
}
