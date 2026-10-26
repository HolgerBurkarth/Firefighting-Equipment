<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Zu einer ID gibt es in der Stadt keinen Standort (fremde und unbekannte IDs nicht unterscheidbar).
 */
final class LocationNotFoundException extends DeviceException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Kein Standort mit der ID %d gefunden.', $id));
    }
}
