<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\User\User;

/**
 * JSON-Darstellung eines Kontos für die Benutzerverwaltung (nie mit Passwort-Hash). Zeiten in UTC.
 */
final class UserJson
{
    /**
     * @return array<string, mixed>
     */
    public static function user(User $user): array
    {
        return [
            'id' => $user->id,
            'label' => $user->label(),
            'email' => $user->email?->value,
            'name' => $user->name?->value,
            'display_name' => $user->displayName?->value,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'status' => $user->status->value,
            'status_label' => $user->status->label(),
            'locked' => $user->isLocked(),
            'email_verified_at' => $user->emailVerifiedAt === null ? null : AuthJson::time($user->emailVerifiedAt),
            'created_at' => AuthJson::time($user->createdAt),
        ];
    }
}
