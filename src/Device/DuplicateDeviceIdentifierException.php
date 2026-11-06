<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Die Kennung ist unter den nicht ausgesonderten Geräten der Stadt schon vergeben (ohne Groß- und
 * Kleinschreibung; entscheidet der UNIQUE-Index auf identifier_key).
 */
final class DuplicateDeviceIdentifierException extends DeviceException
{
    public static function forIdentifier(DeviceIdentifier $identifier): self
    {
        return new self(sprintf(
            'Die Nummer „%s“ ist bereits an eine andere Ausrüstung vergeben (Groß- und Kleinschreibung zählt dabei nicht).',
            $identifier->value,
        ));
    }

    /**
     * Wiederaufnahme ohne neue Kennung, die alte ist inzwischen an eine andere Ausrüstung vergeben.
     */
    public static function onReinstatement(DeviceIdentifier $identifier): self
    {
        return new self(sprintf(
            'Die Nummer „%s“ ist inzwischen an eine andere Ausrüstung vergeben. Bitte beim Wiederaufnehmen eine neue Nummer angeben (Feld "identifier").',
            $identifier->value,
        ));
    }
}
