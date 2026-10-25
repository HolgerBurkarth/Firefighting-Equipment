<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Tenant\TenantSummary;

/**
 * JSON-Darstellung einer Stadt für den Super-Admin, mit Kontenzahlen. Zeiten in UTC.
 */
final class TenantJson
{
    /**
     * @return array<string, mixed>
     */
    public static function tenant(TenantSummary $summary): array
    {
        $tenant = $summary->tenant;

        return [
            'id' => $tenant->id,
            'slug' => $tenant->slug->value,
            'name' => $tenant->name->value,
            'active' => $tenant->active,
            'created_at' => AuthJson::time($tenant->createdAt),
            'updated_at' => AuthJson::time($tenant->updatedAt),
            'counts' => [
                'users_active' => $summary->activeUsers,
                'admins_active' => $summary->activeAdmins,
                'admins_invited' => $summary->invitedAdmins,
            ],
        ];
    }
}
