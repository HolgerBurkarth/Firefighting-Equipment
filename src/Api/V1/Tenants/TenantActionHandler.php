<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\UserRepository;

/**
 * Super-Admin auf eine Stadt, siehe TenantAction. Antwort immer {"tenant": …} mit dem Stand danach.
 * Unbekannte ID: 404 not_found. Umbenannt wird nur der Name (das Kürzel ist unveränderlich).
 * Sperren und Entsperren sind wiederholbar; Sperren beendet alle Sitzungen der Konten der Stadt.
 */
final readonly class TenantActionHandler implements RequestHandler
{
    public function __construct(private TenantAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $id = $context->param('id');
        // Felder vor der Suche der Stadt lesen (400 vor 404).
        $name = $this->action === TenantAction::Rename ? TenantFields::name($context) : null;

        $pdo = $context->db->get();
        $admin = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo));
        try {
            $tenant = $admin->find($id);
            match ($this->action) {
                TenantAction::Show => null,
                TenantAction::Rename => $admin->rename($tenant, $name ?? throw new \LogicException('Name fehlt.')),
                TenantAction::Lock => $admin->setActive($tenant, false),
                TenantAction::Unlock => $admin->setActive($tenant, true),
            };

            return new JsonResponse(['tenant' => TenantJson::tenant($admin->summary($id))]);
        } catch (TenantNotFoundException) {
            throw HttpException::notFound();
        }
    }
}
