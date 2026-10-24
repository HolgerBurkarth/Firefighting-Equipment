<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\User\User;

/**
 * Angemeldetes Konto mit der Sitzung der aktuellen Anfrage.
 */
final readonly class AuthSession
{
    public function __construct(
        public User $user,
        public Session $session,
    ) {
    }
}
