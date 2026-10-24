<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Lebenszyklus eines Kontos (fe_users.status). Sperren ist davon getrennt (locked_at).
 */
enum UserStatus: string
{
    /** Erster Admin einer Stadt, Passwort noch nicht gesetzt. */
    case Invited = 'invited';
    /** Registriert, E-Mail unbestätigt. */
    case Unverified = 'unverified';
    /** Wartet auf Freischaltung durch einen Admin. */
    case Pending = 'pending';
    case Active = 'active';
    /** Anonymisiert; der Datensatz bleibt für die Historie. */
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Invited => 'eingeladen',
            self::Unverified => 'unbestätigt',
            self::Pending => 'wartet auf Freischaltung',
            self::Active => 'aktiv',
            self::Deleted => 'gelöscht',
        };
    }
}
