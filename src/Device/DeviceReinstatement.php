<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Angaben zur Wiederaufnahme eines ausgesonderten Geräts (POST devices/{id}/reinstate): neuer
 * Status (nie ausgesondert), optional ein anderer Standort (sonst der bisherige), eine neue Kennung
 * (falls die alte inzwischen vergeben ist), ein neuer oder kein Scan-Code (falls der alte inzwischen
 * vergeben ist) und ein anderer Gerätetyp (falls der alte deaktiviert ist). null = unverändert (beim
 * Scan-Code entscheidet $hasScanCode, null entfernt ihn dann); gleiche Werte werden ignoriert.
 */
final readonly class DeviceReinstatement
{
    public function __construct(
        public DeviceStatus $status,
        public ?int $locationId = null,
        public ?DeviceIdentifier $identifier = null,
        public ?int $deviceTypeId = null,
        /** scan_code angegeben (auch null = entfernen) */
        public bool $hasScanCode = false,
        public ?ScanCode $scanCode = null,
    ) {
        if ($status === DeviceStatus::Retired) {
            throw new \LogicException('Ein wieder aufgenommenes Gerät kann nicht ausgesondert sein.');
        }
        if (!$hasScanCode && $scanCode !== null) {
            throw new \LogicException('Scan-Code ohne $hasScanCode.');
        }
    }
}
