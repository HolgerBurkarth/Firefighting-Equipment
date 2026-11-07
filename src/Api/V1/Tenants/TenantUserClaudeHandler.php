<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Api\V1\Users\UserActionHandler;
use FirefightingEquipment\Api\V1\Users\UserJson;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Claude-Zugang durch den Super-Admin (F12, docs/MCP-KONZEPT.md, Abschnitt 2):
 * - POST api/v1/tenants/{id}/users/{user}/claude-access {access}: nur Konten mit der Rolle Admin.
 * - POST api/v1/tenants/{id}/users/{user}/claude-connections/end {}: jedes Konto der Stadt.
 *
 * Antwort {"user": …}. Unbekannte Stadt bzw. Konto einer anderen Stadt 404, ungültiges access 400,
 * nicht die Test-Stadt, Konto ohne Admin, nicht aktiv oder gelöscht 409 rule_violation.
 */
final readonly class TenantUserClaudeHandler implements RequestHandler
{
    public function __construct(private bool $endConnections)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $id = $context->param('id');
        $userId = $context->param('user');
        // Felder vor der Suche lesen (400 vor 404).
        $access = $this->endConnections ? null : UserActionHandler::claudeAccess($context);

        $pdo = $context->db->get();
        $admin = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo));
        try {
            $tenant = $admin->find($id);
            $user = $access === null
                ? $admin->endClaudeConnections($tenant, $userId)
                : $admin->setClaudeAccess($tenant, $userId, $access);
        } catch (TenantNotFoundException | UserNotFoundException) {
            throw HttpException::notFound();
        } catch (UserRuleViolationException $e) {
            throw HttpException::ruleViolation($e->getMessage());
        }

        return new JsonResponse(['user' => UserJson::forTenant($pdo, $tenant)($user)]);
    }
}
