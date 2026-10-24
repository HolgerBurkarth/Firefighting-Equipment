<?php

declare(strict_types=1);

namespace FirefightingEquipment\Migration;

/**
 * Gleicht das Protokoll mit den Dateien ab und ermittelt die offenen Migrationen.
 */
final class MigrationVerifier
{
    /**
     * @param list<MigrationFile> $files lückenlos ab 0001 (siehe MigrationDirectory)
     * @param list<AppliedMigration> $applied
     *
     * @return list<MigrationFile> offene Migrationen in Ausführungsreihenfolge
     *
     * @throws MigrationException bei Lücke im Protokoll, fehlender, umbenannter oder geänderter Datei
     */
    public static function pending(array $files, array $applied): array
    {
        $filesByVersion = [];
        foreach ($files as $file) {
            $filesByVersion[$file->version] = $file;
        }

        usort($applied, static fn (AppliedMigration $a, AppliedMigration $b): int => $a->version <=> $b->version);

        $expected = 1;
        foreach ($applied as $entry) {
            if ($entry->version !== $expected) {
                throw new MigrationException(sprintf(
                    'Das Protokoll ist lückenhaft: Version %04d fehlt, obwohl %s ausgeführt wurde.',
                    $expected,
                    $entry->filename,
                ));
            }

            $file = $filesByVersion[$entry->version] ?? throw new MigrationException(sprintf(
                'Die bereits ausgeführte Migration %s fehlt im Migrationsordner.'
                . ' Ausgeführte Migrationen dürfen nie gelöscht werden.',
                $entry->filename,
            ));

            if ($file->filename !== $entry->filename) {
                throw new MigrationException(sprintf(
                    'Migration %04d wurde als %s ausgeführt, die Datei heißt jetzt %s.'
                    . ' Ausgeführte Migrationen dürfen nie umbenannt werden.',
                    $entry->version,
                    $entry->filename,
                    $file->filename,
                ));
            }

            if ($file->checksum !== $entry->checksum) {
                throw new MigrationException(sprintf(
                    'Die bereits ausgeführte Migration %s wurde geändert (Prüfsumme im Protokoll %s, Datei %s).'
                    . ' Ausgeführte Migrationen dürfen nie geändert werden; Korrekturen gehören in eine neue Migration.',
                    $entry->filename,
                    $entry->checksum,
                    $file->checksum,
                ));
            }

            $expected++;
        }

        return array_values(array_filter($files, static fn (MigrationFile $file): bool => $file->version >= $expected));
    }
}
