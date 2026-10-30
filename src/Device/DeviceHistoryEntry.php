<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Eintrag der Historie mit dem Gerät, zu dem er gehört (für den Export der Historie einer Stadt).
 * Kennung und Gerätetyp sind der aktuelle Stand des Geräts; da Kennungen nach dem Aussondern neu
 * vergeben werden dürfen, unterscheidet nur die Geräte-Nr. (ID) sicher.
 */
final readonly class DeviceHistoryEntry
{
    public function __construct(
        public DeviceEvent $event,
        public int $deviceId,
        public string $identifier,
        public int $deviceTypeId,
        public string $deviceTypeName,
    ) {
    }
}
