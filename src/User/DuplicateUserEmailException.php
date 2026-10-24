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
}
