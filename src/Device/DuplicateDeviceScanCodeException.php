<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Der Scan-Code ist unter den nicht ausgesonderten Geräten der Stadt schon vergeben (exakter
 * Vergleich; entscheidet der UNIQUE-Index auf scan_code_key).
 */
final class DuplicateDeviceScanCodeException extends DeviceException
{
    public static function forScanCode(ScanCode $scanCode): self
    {
        return new self(sprintf(
            'Der Scan-Code „%s“ ist bereits an ein anderes Gerät vergeben.',
            ScanCode::display($scanCode->value),
        ));
    }

    /**
     * Wiederaufnahme ohne neuen Scan-Code, der alte ist inzwischen an ein anderes Gerät vergeben.
     */
    public static function onReinstatement(ScanCode $scanCode): self
    {
        return new self(sprintf(
            'Der Scan-Code „%s“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen einen neuen Scan-Code angeben (Feld "scan_code") oder ihn mit null entfernen.',
            ScanCode::display($scanCode->value),
        ));
    }
}
