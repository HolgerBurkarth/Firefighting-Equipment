<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Das gesuchte Konto gibt es nicht.
 */
final class UserNotFoundException extends UserException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Kein Konto mit der ID %d gefunden.', $id));
    }

    public static function superAdmin(EmailAddress $email): self
    {
        return new self(sprintf('Kein Super-Admin mit der E-Mail-Adresse „%s“ gefunden.', $email->value));
    }
}
