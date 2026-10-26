<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\NewDevice;

/**
 * Geplantes Ergebnis einer Zeile: für die Vorschau (Ergebnis, Änderungen, Meldungen, Warnungen) und
 * zum Schreiben (neues Gerät bzw. Änderungen am bestehenden).
 */
final readonly class ImportRowPlan
{
    /**
     * @param list<ImportChange>                                     $changes
     * @param list<string>                                           $messages     Fehler bzw. Grund fürs Überspringen
     * @param list<string>                                           $warnings     Hinweise, die nicht blockieren
     * @param array<string, array{?string, ?string, int|string|null}> $fieldChanges DeviceField-Wert => [alt, neu (Historie), Spaltenwert]
     */
    private function __construct(
        public int $line,
        /** Kennung wie in der Datei */
        public string $identifier,
        public ImportResult $result,
        /** bestehendes Gerät (Stand beim Prüfen) */
        public ?Device $device = null,
        public array $changes = [],
        public array $messages = [],
        public array $warnings = [],
        /** identifier_key der Kennung (neue Geräte) */
        public ?string $key = null,
        public ?NewDevice $newDevice = null,
        public array $fieldChanges = [],
        public bool $dueChanged = false,
        public ?DeviceDate $due = null,
        /** neuer Standort, null = unverändert */
        public ?int $locationId = null,
        /** neuer Status, null = unverändert */
        public ?DeviceStatus $status = null,
    ) {
    }

    /**
     * @param list<string> $messages
     */
    public static function error(int $line, string $identifier, array $messages, ?Device $device = null): self
    {
        return new self($line, $identifier, ImportResult::Error, $device, messages: $messages);
    }

    public static function skipped(int $line, string $identifier, string $message): self
    {
        return new self($line, $identifier, ImportResult::Skipped, messages: [$message]);
    }

    /**
     * @param list<ImportChange> $changes
     * @param list<string>       $warnings
     */
    public static function created(int $line, string $key, NewDevice $device, array $changes, array $warnings): self
    {
        return new self($line, $device->identifier->value, ImportResult::New, null, $changes, [], $warnings, $key, $device);
    }

    /**
     * Bestehendes Gerät; ohne Änderungen Ergebnis „keine Änderung“.
     *
     * @param list<ImportChange>                                     $changes
     * @param list<string>                                           $warnings
     * @param array<string, array{?string, ?string, int|string|null}> $fieldChanges
     */
    public static function updated(
        int $line,
        string $identifier,
        Device $device,
        array $changes,
        array $warnings,
        array $fieldChanges,
        bool $dueChanged,
        ?DeviceDate $due,
        ?int $locationId,
        ?DeviceStatus $status,
    ): self {
        return new self(
            $line,
            $identifier,
            $changes === [] ? ImportResult::Unchanged : ImportResult::Changed,
            $device,
            $changes,
            [],
            $warnings,
            null,
            null,
            $fieldChanges,
            $dueChanged,
            $due,
            $locationId,
            $status,
        );
    }

    /**
     * Was das Kennzeichen der Vorschau absichert: Ergebnis, Gerät mit Version und alle Änderungen
     * einschließlich der IDs von Gerätetyp und Standort.
     *
     * @return list<mixed>
     */
    public function fingerprint(): array
    {
        return [
            $this->line,
            $this->result->value,
            $this->device?->id,
            $this->device?->version,
            array_map(static fn (ImportChange $c): array => [$c->column->value, $c->old, $c->new], $this->changes),
            $this->newDevice === null ? null : [$this->newDevice->deviceTypeId, $this->newDevice->locationId],
            array_map(static fn (array $c): int|string|null => $c[2], $this->fieldChanges),
            $this->locationId,
        ];
    }
}
