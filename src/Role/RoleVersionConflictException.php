<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Die Rolle wurde seit dem Laden geändert (erwartete Version veraltet). $current ist der aktuelle
 * Stand; es wurde nichts geschrieben.
 */
final class RoleVersionConflictException extends RoleException
{
    public function __construct(public readonly Role $current, public readonly RoleUsage $usage)
    {
        parent::__construct(sprintf(
            'Die Rolle „%s“ wurde inzwischen geändert. Bitte die Seite neu laden und die Änderung erneut vornehmen.',
            $current->name,
        ));
    }
}
