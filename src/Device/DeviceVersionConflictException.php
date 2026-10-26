<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Das Gerät wurde seit dem Laden geändert (erwartete Version veraltet). $current ist der aktuelle
 * Stand; es wurde nichts geschrieben.
 */
final class DeviceVersionConflictException extends DeviceException
{
    public function __construct(public readonly Device $current)
    {
        parent::__construct(sprintf(
            'Das Gerät „%s“ wurde inzwischen geändert (aktuelle Version %d). Bitte die Daten neu laden und die Änderung erneut vornehmen.',
            $current->identifier->value,
            $current->version,
        ));
    }
}
