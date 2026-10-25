<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Die E-Mail-Adresse ist in dieser Stadt (bzw. unter den Super-Admins) bereits vergeben.
 */
final class DuplicateUserEmailException extends UserException
{
    public static function for(EmailAddress $email): self
    {
        return new self(sprintf('Es gibt bereits ein Konto mit der E-Mail-Adresse „%s“.', $email->value));
    }

    /**
     * Einladung als Admin per Kommandozeile für eine Adresse, die in der Stadt schon ein Konto hat
     * (offene Einladungen werden stattdessen erneuert).
     */
    public static function notInvitable(EmailAddress $email, UserStatus $status): self
    {
        return new self(sprintf(
            'Für „%s“ gibt es in dieser Stadt bereits ein Konto (Status: %s). Einladen geht nur für neue Adressen; offene Einladungen werden erneuert.',
            $email->value,
            $status->label(),
        ));
    }
}
