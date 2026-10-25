<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Invitation;

use FirefightingEquipment\Api\V1\Auth\AuthJson;
use FirefightingEquipment\Invitation\InvitationCode;
use FirefightingEquipment\User\User;

/**
 * JSON-Darstellung eines Einladungscodes (nie mit Klartext oder Hash). Zeiten in UTC.
 */
final class InvitationJson
{
    /**
     * @param User|null $creator null, falls das Konto nicht geladen werden konnte
     *
     * @return array<string, mixed>
     */
    public static function code(InvitationCode $code, ?User $creator): array
    {
        return [
            'id' => $code->id,
            'label' => $code->label?->value,
            'status' => $code->status->value,
            'status_label' => $code->status->label(),
            'use_count' => $code->useCount,
            'max_uses' => $code->maxUses,
            'expires_at' => AuthJson::time($code->expiresAt),
            'created_at' => AuthJson::time($code->createdAt),
            'revoked_at' => $code->revokedAt === null ? null : AuthJson::time($code->revokedAt),
            'created_by' => [
                'id' => $code->createdBy,
                'label' => $creator?->label() ?? sprintf('#%d', $code->createdBy),
            ],
        ];
    }
}
