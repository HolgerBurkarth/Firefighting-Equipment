<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;

/**
 * Persönliche Daten, von denen kein Teil im Passwort vorkommen darf.
 */
final readonly class PasswordContext
{
    /**
     * @param list<PersonName> $names z. B. Name und Anzeigename
     * @param Tenant|null      $tenant null beim Super-Admin
     */
    public function __construct(
        public ?EmailAddress $email = null,
        public array $names = [],
        public ?Tenant $tenant = null,
    ) {
    }
}
