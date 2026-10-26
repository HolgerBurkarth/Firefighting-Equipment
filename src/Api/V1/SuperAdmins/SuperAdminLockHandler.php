<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\SuperAdmins;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * POST api/v1/superadmins/{id}/lock bzw. unlock {} (Super-Admin): einen anderen Super-Admin sperren
 * bzw. entsperren (deaktivieren statt löschen; wiederholbar). Sperren beendet alle Sitzungen des
 * Kontos. Antwort {"super_admin": …} mit dem Stand danach.
 *
 * Unbekannte ID oder Konto einer Stadt: 404. Sich selbst sperren, geschütztes Konto, letzter
 * aktiver Super-Admin: 409 rule_violation.
 */
final readonly class SuperAdminLockHandler implements RequestHandler
{
    public function __construct(private bool $lock)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $id = $context->param('id');
        $users = new UserRepository($context->db->get());

        $user = $users->findById($id);
        if ($user === null || $user->role !== UserRole::SuperAdmin) {
            throw HttpException::notFound();
        }

        try {
            if ($this->lock) {
                if ($user->id === $context->auth()->user->id) {
                    throw UserRuleViolationException::selfLock();
                }
                $users->lock($id);
            } else {
                $users->unlock($id);
            }
        } catch (UserNotFoundException) {
            throw HttpException::notFound();
        } catch (UserRuleViolationException $e) {
            throw HttpException::ruleViolation($e->getMessage());
        }

        return new JsonResponse([
            'super_admin' => SuperAdminJson::superAdmin($users->findById($id) ?? throw HttpException::notFound()),
        ]);
    }
}
