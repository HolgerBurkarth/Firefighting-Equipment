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

    public static function notPending(int $id): self
    {
        return new self(sprintf(
            'Das Konto #%d wartet nicht auf Freischaltung (E-Mail-Adresse noch nicht bestätigt oder bereits freigeschaltet).',
            $id,
        ));
    }

    public static function notARegistration(int $id): self
    {
        return new self(sprintf(
            'Das Konto #%d ist keine offene Registrierung. Nur unbestätigte oder wartende Registrierungen können abgelehnt werden.',
            $id,
        ));
    }

    public static function selfLock(): self
    {
        return new self('Sie können Ihr eigenes Konto nicht sperren.');
    }

    public static function selfDemote(): self
    {
        return new self('Sie können Ihrem eigenen Konto die Admin-Rolle nicht entziehen.');
    }

    public static function selfDelete(): self
    {
        return new self('Sie können Ihr eigenes Konto nicht löschen.');
    }

    public static function noPasswordYet(int $id): self
    {
        return new self(sprintf(
            'Für das Konto #%d kann noch kein Passwort gesetzt werden: Die Einladung ist nicht abgeschlossen.',
            $id,
        ));
    }

    public static function notInvited(int $id): self
    {
        return new self(sprintf(
            'Das Konto #%d ist keine offene Einladung. Erneut senden geht nur, solange die Einladung nicht angenommen ist.',
            $id,
        ));
    }

    public static function invitationLocked(int $id): self
    {
        return new self(sprintf('Das Konto #%d ist gesperrt. Zuerst entsperren, dann die Einladung erneut senden.', $id));
    }

    public static function tenantInactive(): self
    {
        return new self('Die Stadt ist gesperrt. Zuerst entsperren, dann einladen.');
    }
}
