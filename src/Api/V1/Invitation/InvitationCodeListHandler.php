<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Invitation;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Invitation\InvitationCode;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;

/**
 * GET api/v1/invitation-codes (Admin): alle Codes der Stadt, neueste zuerst, ohne Klartext.
 */
final readonly class InvitationCodeListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $codes = new InvitationCodeRepository($pdo)->listByTenant($tenant);
        $users = [];
        foreach (new UserRepository($pdo)->listByTenant($tenant) as $user) {
            $users[$user->id] = $user;
        }

        return new JsonResponse([
            'invitation_codes' => array_map(
                static fn (InvitationCode $code): array => InvitationJson::code($code, $users[$code->createdBy] ?? null),
                $codes,
            ),
        ]);
    }
}
