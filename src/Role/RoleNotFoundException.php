<?php

declare(strict_types=1);

namespace FirefightingEquipment\Role;

/**
 * Die Rolle gibt es in dieser Stadt nicht (unbekannte oder fremde ID).
 */
final class RoleNotFoundException extends RoleException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Die Rolle #%d gibt es nicht.', $id));
    }
}
