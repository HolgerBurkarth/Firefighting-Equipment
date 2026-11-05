<?php

declare(strict_types=1);

namespace FirefightingEquipment\Permission;

/**
 * Stufe eines Bereichs (fe_role_areas.level). „Kein Zugriff“ ist keine Zeile. Ändern schließt
 * Ansehen ein.
 */
enum AreaLevel: string
{
    case Read = 'read';
    case Write = 'write';

    public function includes(self $needed): bool
    {
        return $this === self::Write || $needed === self::Read;
    }

    public function label(): string
    {
        return match ($this) {
            self::Read => 'Ansehen',
            self::Write => 'Ändern',
        };
    }
}
