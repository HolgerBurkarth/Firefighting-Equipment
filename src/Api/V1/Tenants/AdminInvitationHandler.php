<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\AdminInvitationMail;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Einladung als Admin für eine bestehende Stadt (Super-Admin), danach Mail mit Link (24 h):
 * - POST api/v1/tenants/{id}/admins {email}: neues Konto (Status invited), 201.
 * - POST api/v1/tenants/{id}/users/{user}/resend-invitation {}: neuer Link für eine offene
 *   Einladung, der alte wird ungültig, 200.
 *
 * Antwort {"user", "mail_sent"} (nie Link oder Token). Unbekannte Stadt bzw. Konto einer anderen
 * Stadt 404, Adresse in der Stadt schon vergeben 409 email_taken, gesperrte Stadt, keine offene
 * Einladung oder gesperrtes Konto 409 rule_violation.
 */
final readonly class AdminInvitationHandler implements RequestHandler
{
    public function __construct(private bool $resend)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $id = $context->param('id');
        $email = $this->resend ? null : TenantFields::email($context, 'email');

        $pdo = $context->db->get();
        $admin = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo));
        try {
            $tenant = $admin->find($id);
            $invitation = $email === null
                ? $admin->renewInvitation($tenant, $context->param('user'))
                : $admin->inviteAdmin($tenant, $email);
        } catch (TenantNotFoundException | UserNotFoundException) {
            throw HttpException::notFound();
        } catch (DuplicateUserEmailException) {
            throw TenantFields::emailTaken($email ?? throw new \LogicException('E-Mail fehlt.'));
        } catch (UserRuleViolationException $e) {
            throw HttpException::ruleViolation($e->getMessage());
        }

        $mailSent = AdminInvitationMail::send($context->notifier(), $context->urls, $invitation);

        return new JsonResponse(
            ['user' => UserJson::user($invitation->user), 'mail_sent' => $mailSent],
            $this->resend ? 200 : 201,
        );
    }
}
