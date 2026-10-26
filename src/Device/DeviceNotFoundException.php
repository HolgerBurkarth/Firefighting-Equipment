<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Zu einer ID gibt es in der Stadt kein Gerät (fremde und unbekannte IDs nicht unterscheidbar).
 */
final class DeviceNotFoundException extends DeviceException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Kein Gerät mit der ID %d gefunden.', $id));
    }
}
