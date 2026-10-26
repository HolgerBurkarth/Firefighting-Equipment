<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Zu einer ID gibt es in der Stadt keinen Gerätetyp (fremde und unbekannte IDs nicht unterscheidbar).
 */
final class DeviceTypeNotFoundException extends DeviceException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Kein Gerätetyp mit der ID %d gefunden.', $id));
    }
}
