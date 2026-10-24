<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\User\UserRole;

/**
 * Wer eine Route aufrufen darf. Geprüft wird zentral in Application, bevor der Handler läuft.
 */
enum Access: string
{
    /** Ohne Anmeldung; der Handler erhält keine Sitzung. */
    case Public = 'public';
    /** Jedes angemeldete Konto (unter service nur Super-Admins). */
    case Authenticated = 'authenticated';
    /** Admins der Stadt; nur bei Routen unter einer Stadt. */
    case Admin = 'admin';
    /** Super-Admins; nur bei Routen unter service. */
    case SuperAdmin = 'superadmin';

    public function allows(UserRole $role): bool
    {
        return match ($this) {
            self::Public, self::Authenticated => true,
            self::Admin => $role === UserRole::Admin,
            self::SuperAdmin => $role === UserRole::SuperAdmin,
        };
    }
}
