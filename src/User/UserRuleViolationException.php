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

    public static function protectedAccount(int $id): self
    {
        return new self(sprintf(
            'Das Konto #%d ist geschützt und kann nicht gesperrt, gelöscht oder geändert werden.'
            . ' Der Schutz lässt sich nur per Kommandozeile auf dem Server entfernen.',
            $id,
        ));
    }

    public static function superAdminNotDeletable(): self
    {
        return new self('Super-Admins können nicht gelöscht werden, nur gesperrt.');
    }

    public static function notProtectable(int $id): self
    {
        return new self(sprintf(
            'Das Konto #%d kann nicht geschützt werden: Nur aktive, nicht gesperrte Super-Admins können geschützt werden.',
            $id,
        ));
    }

    public static function deviceLogNotAllowed(int $id): self
    {
        return new self(sprintf(
            'Für das Konto #%d gibt es die Benachrichtigung „Log“ nicht: Sie steht nur Super-Admins und Konten zur Verfügung, deren Rolle Log-Mails empfangen darf.',
            $id,
        ));
    }

    public static function claudeAccessSuperAdmin(): self
    {
        return new self('Super-Admins haben keinen Claude-Zugang.');
    }

    public static function claudeAccessNotActive(int $id): self
    {
        return new self(sprintf(
            'Den Claude-Zugang gibt es nur für aktive Konten; das Konto #%d ist nicht freigeschaltet.',
            $id,
        ));
    }

    public static function claudeAccessOnlyByAdmins(): self
    {
        return new self('Den Claude-Zugang setzt nur ein Konto mit der Rolle Admin.');
    }

    public static function claudeAccessOfAdminBySuperAdmin(): self
    {
        return new self('Für Admins setzt den Claude-Zugang der Super-Admin.');
    }

    public static function claudeAccessOfAdminsOnly(): self
    {
        return new self('Der Super-Admin setzt den Claude-Zugang nur für Admins; für andere Konten setzt ihn ein Admin der Stadt.');
    }

    public static function selfClaudeAccess(): self
    {
        return new self('Den eigenen Claude-Zugang kann man nicht ändern.');
    }

    public static function claudeUnavailable(): self
    {
        return new self('Den Claude-Zugang gibt es bisher nur in der Test-Stadt.');
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

    public static function selfRole(): self
    {
        return new self('Sie können Ihre eigene Rolle nicht ändern.');
    }

    public static function adminRoleOnlyByAdmins(): self
    {
        return new self('Die Rolle „Admin“ vergeben und entziehen nur Admins.');
    }

    public static function adminOnlyByAdmins(): self
    {
        return new self('Konten mit der Rolle „Admin“ ändern, sperren und löschen nur Admins.');
    }

    public static function roleBeyondOwnRights(string $role): self
    {
        return new self(sprintf('Die Rolle „%s“ hat Rechte, die Ihre eigene Rolle nicht hat; Sie können sie daher nicht vergeben.', $role));
    }

    public static function accountBeyondOwnRights(string $role): self
    {
        return new self(sprintf(
            'Das Konto hat die Rolle „%s“ mit Rechten, die Ihre eigene Rolle nicht hat; Sie können es daher nicht ändern.',
            $role,
        ));
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
