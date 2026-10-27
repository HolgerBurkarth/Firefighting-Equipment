<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Notice;

use FirefightingEquipment\Device\DeviceException;

/**
 * Hinweis unbekannt oder aus einer anderen Stadt (404).
 */
final class NoticeNotFoundException extends DeviceException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Hinweis #%d wurde nicht gefunden.', $id));
    }
}
