<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\AdminInvitationMail;
use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\DuplicateUserEmailException;
use FirefightingEquipment\User\UserRepository;

/**
 * POST api/v1/tenants {slug, name, admin_email} (Super-Admin): Stadt, ersten Admin (Status
 * invited) und Link set_password in einer Transaktion anlegen, danach die Einladung mailen.
 *
 * 201 {"tenant", "user", "mail_sent"}; mail_sent = false heißt: Einladung erneut senden. Ungültige
 * Felder 400, vergebenes Kürzel 409 slug_taken. Link und Token stehen nie in der Antwort.
 */
final readonly class TenantCreateHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $slug = TenantFields::slug($context);
        $name = TenantFields::name($context);
        $email = TenantFields::email($context, 'admin_email');

        $pdo = $context->db->get();
        $admin = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo));
        try {
            $invitation = $admin->create($slug, $name, $email);
        } catch (DuplicateTenantSlugException $e) {
            throw HttpException::slugTaken($e->getMessage());
        } catch (DuplicateUserEmailException) {
            // Kann in einer neuen Stadt nicht vorkommen; der Vollständigkeit halber.
            throw TenantFields::emailTaken($email);
        }

        $mailSent = AdminInvitationMail::send($context->notifier(), $context->urls, $invitation);

        return new JsonResponse([
            'tenant' => TenantJson::tenant($admin->summary($invitation->tenant->id)),
            'user' => UserJson::user($invitation->user),
            'mail_sent' => $mailSent,
        ], 201);
    }
}
