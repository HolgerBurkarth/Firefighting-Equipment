<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Eine Änderung verletzt eine Geschäftsregel (z. B. letzter Admin einer Stadt).
 */
final class UserRuleViolationException extends UserException
{
    public static function lastAdmin(): self
    {
        return new self(
            'Das ist der letzte aktive, nicht gesperrte Admin dieser Stadt. Das Konto kann nicht gesperrt,'
            . ' herabgestuft oder gelöscht werden; zuerst einen anderen Admin freischalten.',
        );
    }

    public static function lastSuperAdmin(): self
    {
        return new self(
            'Das ist der letzte aktive, nicht gesperrte Super-Admin. Das Konto kann nicht gesperrt oder'
            . ' gelöscht werden; zuerst einen anderen Super-Admin anlegen.',
        );
    }

    public static function deleted(int $id): self
    {
        return new self(sprintf('Das Konto #%d ist gelöscht und kann nicht mehr geändert werden.', $id));
    }

    public static function superAdminRole(): self
    {
        return new self('Die Rolle Super-Admin kann weder vergeben noch entzogen werden.');
    }

    public static function noPasswordYet(int $id): self
    {
        return new self(sprintf(
            'Für das Konto #%d kann noch kein Passwort gesetzt werden: Die Einladung ist nicht abgeschlossen.',
            $id,
        ));
    }
}
