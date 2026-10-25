<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Ergebnis von UserRepository::register: das neue Konto oder, wenn die E-Mail in der Stadt schon
 * vergeben war, das bestehende (dann wurde nichts geändert).
 */
final readonly class Registration
{
    public function __construct(public User $user, public bool $created)
    {
    }
}
