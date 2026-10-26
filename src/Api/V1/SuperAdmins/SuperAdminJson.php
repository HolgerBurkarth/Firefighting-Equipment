<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\SuperAdmins;

use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\User\User;

/**
 * JSON-Darstellung eines Super-Admins: wie UserJson, zusätzlich "protected" (nur lesbar; gesetzt
 * wird der Schutz nur per Kommandozeile).
 */
final class SuperAdminJson
{
    /**
     * @return array<string, mixed>
     */
    public static function superAdmin(User $user): array
    {
        return [...UserJson::user($user), 'protected' => $user->protected];
    }
}
