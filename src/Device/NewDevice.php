<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Angaben zum Anlegen eines Geräts (Texte schon über DeviceText geprüft). Ein neues Gerät ist nie
 * ausgesondert.
 */
final readonly class NewDevice
{
    /**
     * @throws InvalidDeviceValueException Status ausgesondert
     */
    public function __construct(
        public DeviceIdentifier $identifier,
        public int $deviceTypeId,
        public int $locationId,
        public DeviceStatus $status = DeviceStatus::Ready,
        public ?string $serialNumber = null,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?DeviceDate $procuredOn = null,
        public ?DeviceDate $nextInspectionDue = null,
        public ?string $note = null,
    ) {
        if ($status === DeviceStatus::Retired) {
            throw new InvalidDeviceValueException('Ein neues Gerät kann nicht ausgesondert sein.');
        }
    }
}
