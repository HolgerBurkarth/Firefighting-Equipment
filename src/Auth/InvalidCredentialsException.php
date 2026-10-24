<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * E-Mail unbekannt oder Passwort falsch; beides bewusst nicht unterscheidbar.
 */
final class InvalidCredentialsException extends AuthException
{
    public function __construct()
    {
        parent::__construct('E-Mail-Adresse oder Passwort ist falsch.');
    }
}
