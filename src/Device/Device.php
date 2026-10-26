<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Gerät einer Stadt mit Namen von Gerätetyp und Standort (samt übergeordnetem Standort für den
 * Pfad „HLF 20 / G1“). Geräte werden nie gelöscht; ausgesondert = Status retired.
 */
final readonly class Device
{
    public function __construct(
        public int $id,
        public DeviceIdentifier $identifier,
        public int $deviceTypeId,
        public string $deviceTypeName,
        public int $locationId,
        public string $locationName,
        /** null = Standort der Ebene 1 */
        public ?string $parentLocationName,
        public DeviceStatus $status,
        public ?string $serialNumber,
        public ?string $manufacturer,
        public ?string $model,
        public ?DeviceDate $procuredOn,
        public ?DeviceDate $nextInspectionDue,
        public ?string $note,
        /** Versionszähler gegen gleichzeitige Änderungen, ab 1 */
        public int $version,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Standort als Pfad, z. B. „HLF 20 / G1“ (Ebene 1: nur der Name).
     */
    public function locationPath(): string
    {
        return $this->parentLocationName === null
            ? $this->locationName
            : $this->parentLocationName . ' / ' . $this->locationName;
    }

    public function isRetired(): bool
    {
        return $this->status === DeviceStatus::Retired;
    }

    /**
     * Wert eines Stammdatenfelds als Text für die Historie (beim Gerätetyp dessen Name).
     */
    public function fieldText(DeviceField $field): ?string
    {
        return match ($field) {
            DeviceField::Identifier => $this->identifier->value,
            DeviceField::DeviceType => $this->deviceTypeName,
            DeviceField::SerialNumber => $this->serialNumber,
            DeviceField::Manufacturer => $this->manufacturer,
            DeviceField::Model => $this->model,
            DeviceField::ProcuredOn => $this->procuredOn?->value,
            DeviceField::Note => $this->note,
        };
    }
}
