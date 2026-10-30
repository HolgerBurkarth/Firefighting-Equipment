<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Ergebnis der Prüfung einer Import-Datei gegen die Datenbank.
 *
 * Das Kennzeichen ($token) gibt es nur ohne Fehler: SHA-256 über den Hash der Datei und den Plan
 * aller Zeilen (Ergebnis, Gerät mit Version, Änderungen samt IDs). Beim Übernehmen wird die Datei
 * erneut geprüft; weicht das Kennzeichen ab, hat sich seit der Vorschau etwas geändert. Ein Geheimnis
 * braucht es nicht, denn es wird nie ungeprüft übernommen.
 */
final readonly class DeviceImportPlan
{
    public ?string $token;

    /**
     * @param list<ImportRowPlan>      $rows
     * @param list<UnknownImportValue> $unknownDeviceTypes Namen ohne passenden Gerätetyp
     * @param list<UnknownImportValue> $unknownLocations   Pfade ohne passenden Standort
     */
    public function __construct(
        string $fileHash,
        public array $rows,
        public array $unknownDeviceTypes,
        public array $unknownLocations,
    ) {
        $this->token = $this->count(ImportResult::Error) > 0 ? null : hash('sha256', json_encode(
            [$fileHash, array_map(static fn (ImportRowPlan $row): array => $row->fingerprint(), $rows)],
            JSON_THROW_ON_ERROR,
        ));
    }

    public function count(ImportResult $result): int
    {
        return count(array_filter($this->rows, static fn (ImportRowPlan $row): bool => $row->result === $result));
    }

    /** Zeilen mit mindestens einer Warnung */
    public function warningCount(): int
    {
        return count(array_filter($this->rows, static fn (ImportRowPlan $row): bool => $row->warnings !== []));
    }
}
