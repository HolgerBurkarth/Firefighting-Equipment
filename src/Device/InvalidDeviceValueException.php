<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Ein Wert verletzt die Regeln (z. B. Name zu lang, übergeordneter Standort unbekannt).
 */
final class InvalidDeviceValueException extends DeviceException
{
}
