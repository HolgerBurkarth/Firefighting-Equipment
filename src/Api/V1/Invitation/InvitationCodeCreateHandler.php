<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Invitation;

use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Invitation\InvalidInvitationValueException;
use FirefightingEquipment\Invitation\InvitationCodeLabel;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * POST api/v1/invitation-codes {label?, valid_days, max_uses, role_id?} (Benutzer: Ändern): legt
 * einen Einladungscode an. Der Klartext ("code", Form XXXX-XXXX-XXXX) steht nur in dieser Antwort;
 * gespeichert wird nur sein Hash. Eine leere Bezeichnung gilt als keine.
 *
 * role_id (Plan Phase 1, Schritt 1.6): Rolle nach der Freischaltung; ohne Angabe (oder null) wie
 * bisher die Standard-Rolle Benutzer. Unbekannte Rolle oder die Rolle Admin 400; eine Rolle, die das
 * Konto der Sitzung nicht vergeben darf (F5), 409 rule_violation.
 */
final readonly class InvitationCodeCreateHandler implements RequestHandler
{
    /** Text aus dem Mockup „Einladungscodes“. */
    public const string ADMIN_ROLE_MESSAGE = 'Die Rolle „Admin“ gibt es für Codes nicht: Wer einen Aushang fotografiert,'
        . ' soll nie Admin werden können. Admins ernennen Sie unter „Konten“.';

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $labelInput = $context->optionalStringField('label');
        $validDays = $context->intField('valid_days', 1, InvitationCodeRepository::MAX_VALID_DAYS);
        $maxUses = $context->intField('max_uses', 1, InvitationCodeRepository::MAX_USES);
        $roleId = $context->optionalIntField('role_id', 1, PHP_INT_MAX);
        try {
            $label = $labelInput === null || trim($labelInput) === '' ? null : InvitationCodeLabel::fromString($labelInput);
        } catch (InvalidInvitationValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }

        $pdo = $context->db->get();
        if ($roleId !== null) {
            $role = new RoleRepository($pdo)->find($tenant, $roleId)
                ?? throw HttpException::invalidRequest(RoleNotFoundException::forId($roleId)->getMessage() . ' (Feld "role_id")');
            if ($role->systemRole === SystemRole::Admin) {
                throw HttpException::invalidRequest(self::ADMIN_ROLE_MESSAGE);
            }
            try {
                UserActionHandler::administration($context, $tenant)->assertMayAssign($role);
            } catch (UserRuleViolationException $e) {
                throw HttpException::ruleViolation($e->getMessage());
            }
        }

        $admin = $context->auth()->user;
        try {
            [$code, $value] = new InvitationCodeRepository($pdo)->create($tenant, $admin->id, $label, $validDays, $maxUses, $roleId);
        } catch (RoleNotFoundException $e) {
            throw HttpException::invalidRequest($e->getMessage() . ' (Feld "role_id")');
        }

        return new JsonResponse(['invitation_code' => InvitationJson::code($code, $admin), 'code' => $value->display()], 201);
    }
}
