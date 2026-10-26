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
            'Die Kennung „%s“ ist bereits an ein anderes Gerät vergeben (Groß- und Kleinschreibung zählt dabei nicht).',
            $identifier->value,
        ));
    }

    /**
     * Wiederaufnahme ohne neue Kennung, die alte ist inzwischen an ein anderes Gerät vergeben.
     */
    public static function onReinstatement(DeviceIdentifier $identifier): self
    {
        return new self(sprintf(
            'Die Kennung „%s“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen eine neue Kennung angeben (Feld "identifier").',
            $identifier->value,
        ));
    }
}
