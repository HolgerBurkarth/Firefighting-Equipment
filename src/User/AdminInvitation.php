<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Ergebnis einer Einladung als Admin: Stadt, eingeladenes Konto (Status invited) und das Token des
 * Links set_password. Das Token geht nur in die Mail an den Admin, nie in eine API-Antwort.
 */
final readonly class AdminInvitation
{
    public function __construct(public Tenant $tenant, public User $user, public OneTimeToken $token)
    {
    }
}
