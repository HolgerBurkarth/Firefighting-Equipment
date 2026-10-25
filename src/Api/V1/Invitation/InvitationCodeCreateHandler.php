<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Invitation;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Invitation\InvalidInvitationValueException;
use FirefightingEquipment\Invitation\InvitationCodeLabel;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Tenant\Tenant;

/**
 * POST api/v1/invitation-codes {label?, valid_days, max_uses} (Admin): legt einen Einladungscode
 * an. Der Klartext ("code", Form XXXX-XXXX-XXXX) steht nur in dieser Antwort; gespeichert wird nur
 * sein Hash. Eine leere Bezeichnung gilt als keine.
 */
final readonly class InvitationCodeCreateHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $labelInput = $context->optionalStringField('label');
        $validDays = $context->intField('valid_days', 1, InvitationCodeRepository::MAX_VALID_DAYS);
        $maxUses = $context->intField('max_uses', 1, InvitationCodeRepository::MAX_USES);
        try {
            $label = $labelInput === null || trim($labelInput) === '' ? null : InvitationCodeLabel::fromString($labelInput);
        } catch (InvalidInvitationValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }

        $admin = $context->auth()->user;
        [$code, $value] = new InvitationCodeRepository($context->db->get())
            ->create($tenant, $admin->id, $label, $validDays, $maxUses);

        return new JsonResponse(['invitation_code' => InvitationJson::code($code, $admin), 'code' => $value->display()], 201);
    }
}
