<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Der Name ist bereits vergeben: Gerätetyp je Stadt, Standort je Stadt und übergeordnetem
 * Standort (ohne Groß- und Kleinschreibung, „Straße“ = „Strasse“; entscheidet die Datenbank).
 */
final class DuplicateDeviceNameException extends DeviceException
{
    public static function deviceType(DeviceTypeName $name): self
    {
        return new self(sprintf('Es gibt bereits einen Gerätetyp „%s“.', $name->value));
    }

    /**
     * @param LocationName|null $parent null = Ebene 1
     */
    public static function location(LocationName $name, ?LocationName $parent): self
    {
        return new self($parent === null
            ? sprintf('Es gibt bereits einen Standort „%s“ auf oberster Ebene.', $name->value)
            : sprintf('Unter „%s“ gibt es bereits einen Standort „%s“.', $parent->value, $name->value));
    }
}
