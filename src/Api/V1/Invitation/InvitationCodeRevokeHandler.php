<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Invitation;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\User\UserRepository;

/**
 * POST api/v1/invitation-codes/{id}/revoke {} (Admin): widerruft einen Code der eigenen Stadt
 * (wiederholbar). Codes anderer Städte und unbekannte IDs: 404.
 */
final readonly class InvitationCodeRevokeHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $code = new InvitationCodeRepository($pdo)->revoke($tenant, $context->param('id')) ?? throw HttpException::notFound();

        return new JsonResponse([
            'invitation_code' => InvitationJson::code($code, new UserRepository($pdo)->findByTenantAndId($tenant, $code->createdBy)),
        ]);
    }
}
