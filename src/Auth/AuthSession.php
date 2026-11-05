<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Permission\Permissions;
use FirefightingEquipment\User\User;

/**
 * Angemeldetes Konto mit der Sitzung der aktuellen Anfrage und den Rechten seiner Rolle.
 */
final readonly class AuthSession
{
    public function __construct(
        public User $user,
        public Session $session,
        /** Rechte der Rolle; null für Super-Admins (Bereich service, keine Rolle). */
        public ?Permissions $permissions,
    ) {
    }

    public function sessionClass(): SessionClass
    {
        return self::classOf($this->permissions);
    }

    /**
     * @param Permissions|null $permissions null = Super-Admin
     */
    public static function classOf(?Permissions $permissions): SessionClass
    {
        return $permissions === null ? SessionClass::SuperAdmin : SessionClass::forPermissions($permissions);
    }
}
