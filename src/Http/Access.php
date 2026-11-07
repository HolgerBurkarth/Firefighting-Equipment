<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\User\UserRole;

/**
 * Wer eine Route aufrufen darf. Geprüft wird zentral in Application, bevor der Handler läuft.
 * Unter einer Stadt verlangen Routen außer den öffentlichen, den Routen jedes angemeldeten
 * Kontos (auth/*, client-config) und mcp (Claude) Rechte (Permission\Requirement).
 */
enum Access: string
{
    /** Ohne Anmeldung; der Handler erhält keine Sitzung. */
    case Public = 'public';
    /** Jedes angemeldete Konto (unter service nur Super-Admins). */
    case Authenticated = 'authenticated';
    /** Super-Admins; nur bei Routen unter service. */
    case SuperAdmin = 'superadmin';
    /**
     * Claude-Verbindung (Authorization: Bearer, ClaudeSessions); nur unter einer Stadt und nur dort,
     * wo es den Claude-Zugang gibt (sonst 404). Nie mit dem Cookie einer Sitzung.
     */
    case Claude = 'claude';

    public function allows(UserRole $role): bool
    {
        return match ($this) {
            self::Public, self::Authenticated => true,
            self::SuperAdmin => $role === UserRole::SuperAdmin,
            self::Claude => false,
        };
    }
}
