<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Roles;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Device\Location;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Role\DuplicateRoleNameException;
use FirefightingEquipment\Role\InvalidRoleValueException;
use FirefightingEquipment\Role\Role;
use FirefightingEquipment\Role\RoleDraft;
use FirefightingEquipment\Role\RoleException;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\RoleRuleViolationException;
use FirefightingEquipment\Role\RoleUsage;
use FirefightingEquipment\Role\RoleVersionConflictException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantAdministration;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\User\UserRepository;

/**
 * Rollen einer Stadt durch den Super-Admin (Plan Phase 1, Schritt 1.5), siehe RoleAction. Unbekannte
 * Stadt oder Rolle im Pfad 404, Felder vorher (400 vor 404). Fehler: ungültiger Wert, leerer
 * Datenfilter, Standort oder Ausrüstungstyp einer anderen Stadt, unbekannte Vorlage (copy_of,
 * source_role_id) 400; Name vergeben 409 name_taken; veraltete Version 409 version_conflict
 * (details {role}); Rolle Admin ändern, Standard-Rolle löschen, Rolle in Gebrauch (details {accounts,
 * invitation_codes}) und F3 (details {areas, actions}) 409 rule_violation.
 *
 * Kopie (copy_of, dieselbe Stadt): Rechte und Datenfilter, Name ohne Angabe „<Name> (Kopie)“, ist er
 * vergeben, „<Name> (Kopie 2)“ …, höchstens 50 Zeichen (gekürzt wird der Name der Vorlage).
 * Übernahme (source_role_id, andere Stadt): nur die Rechte (F10), Name ohne Angabe der Name der
 * Vorlage; ist er vergeben, mit „(2)“, „(3)“ …. Eine Kopie der Rolle Admin wird eine gewöhnliche Rolle
 * mit allen Rechten des Katalogs.
 */
final readonly class RoleHandler implements RequestHandler
{
    public function __construct(private RoleAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenantId = $context->param('id');
        // Felder vor der Suche lesen (400 vor 404).
        $fields = match ($this->action) {
            RoleAction::Create => self::createFields($context),
            RoleAction::Import => ['source' => $context->intField('source_role_id', 1, PHP_INT_MAX), 'name' => self::copyName($context, 'source_role_id')],
            RoleAction::Save => ['version' => $context->intField('version', 1, PHP_INT_MAX), 'draft' => RoleFields::draft($context, ['version'])],
            RoleAction::Delete => self::noFields($context),
            default => [],
        };

        $pdo = $context->db->get();
        try {
            $tenant = new TenantAdministration(new TenantRepository($pdo), new UserRepository($pdo))->find($tenantId);
        } catch (TenantNotFoundException) {
            throw HttpException::notFound();
        }
        $roles = new RoleRepository($pdo);
        $updatedBy = $context->auth()->user->id;

        try {
            return match ($this->action) {
                RoleAction::List => self::list($roles, $tenant),
                RoleAction::FilterOptions => self::filterOptions($pdo, $tenant),
                RoleAction::Show => self::one($roles, $tenant, $roles->find($tenant, $context->param('role'))
                    ?? throw RoleNotFoundException::forId($context->param('role'))),
                RoleAction::Create => self::one($roles, $tenant, $roles->create($tenant, self::createDraft($roles, $tenant, $fields), $updatedBy), 201),
                RoleAction::Import => self::one($roles, $tenant, $roles->create($tenant, self::importDraft($roles, $tenant, $fields), $updatedBy), 201),
                RoleAction::Save => self::one($roles, $tenant, $roles->update(
                    $tenant,
                    $context->param('role'),
                    $fields['version'],
                    $fields['draft'],
                    $updatedBy,
                )),
                RoleAction::Delete => new JsonResponse(['deleted_role_id' => $roles->delete($tenant, $context->param('role'))->id]),
            };
        } catch (RoleException $e) {
            throw self::http($e);
        }
    }

    /**
     * @return array{draft?: RoleDraft, copy_of?: int, name?: string|null}
     */
    private static function createFields(RequestContext $context): array
    {
        if (!$context->hasField('copy_of')) {
            return ['draft' => RoleFields::draft($context)];
        }

        return ['copy_of' => $context->intField('copy_of', 1, PHP_INT_MAX), 'name' => self::copyName($context, 'copy_of')];
    }

    private static function copyName(RequestContext $context, string $sourceField): ?string
    {
        foreach (array_keys($context->body) as $key) {
            if ($key !== $sourceField && $key !== 'name') {
                throw HttpException::invalidRequest(sprintf('Das Feld "%s" ist hier nicht vorgesehen (möglich: %s, name).', $key, $sourceField));
            }
        }

        return RoleFields::optionalName($context);
    }

    /**
     * @return array{}
     */
    private static function noFields(RequestContext $context): array
    {
        foreach (array_keys($context->body) as $key) {
            throw HttpException::invalidRequest(sprintf('Das Feld "%s" ist hier nicht vorgesehen.', $key));
        }

        return [];
    }

    /**
     * @param array{draft?: RoleDraft, copy_of?: int, name?: string|null} $fields
     *
     * @throws RoleException
     */
    private static function createDraft(RoleRepository $roles, Tenant $tenant, array $fields): RoleDraft
    {
        if (isset($fields['draft'])) {
            return $fields['draft'];
        }
        $copyOf = $fields['copy_of'] ?? throw new \LogicException('Vorlage fehlt.');
        $source = $roles->find($tenant, $copyOf)
            ?? throw new InvalidRoleValueException(sprintf('Die Rolle #%d gibt es in dieser Stadt nicht (Feld "copy_of").', $copyOf));

        return RoleDraft::copyOf($source, $fields['name'] ?? $roles->availableName($tenant, $source->name, copy: true));
    }

    /**
     * @param array{source: int, name: string|null} $fields
     *
     * @throws RoleException
     */
    private static function importDraft(RoleRepository $roles, Tenant $tenant, array $fields): RoleDraft
    {
        $source = $roles->findInAnyTenant($fields['source'])
            ?? throw new InvalidRoleValueException(sprintf('Die Rolle #%d gibt es nicht (Feld "source_role_id").', $fields['source']));
        if ($source->tenantId === $tenant->id) {
            throw new InvalidRoleValueException('Die Rolle gehört schon zu dieser Stadt; bitte „Kopieren“ verwenden.');
        }

        return RoleDraft::copyOf($source, $fields['name'] ?? $roles->availableName($tenant, $source->name), withFilters: false);
    }

    private static function list(RoleRepository $roles, Tenant $tenant): JsonResponse
    {
        $usage = $roles->usage($tenant);

        return new JsonResponse(['roles' => array_map(
            static fn (Role $role): array => RoleJson::role($role, $usage[$role->id] ?? new RoleUsage(0, 0)),
            $roles->listByTenant($tenant),
        )]);
    }

    private static function one(RoleRepository $roles, Tenant $tenant, Role $role, int $status = 200): JsonResponse
    {
        return new JsonResponse(['role' => RoleJson::role($role, $roles->usageOf($tenant, $role->id))], $status);
    }

    /**
     * Auswahl für „Sieht nur …“: alle Standorte (Baum, auch gesperrte) und Ausrüstungstypen (auch
     * deaktivierte) der Stadt, ohne Gerätezahlen.
     */
    private static function filterOptions(\PDO $pdo, Tenant $tenant): JsonResponse
    {
        // Super-Admin: Namen der Standorte und Typen für den Datenfilter, keine Geräte.
        $locations = new LocationRepository($pdo, DeviceVisibility::all())->tree($tenant, true);
        $types = new DeviceTypeRepository($pdo, DeviceVisibility::all())->listByTenant($tenant, true);
        $location = static fn (Location $l): array => [
            'id' => $l->id,
            'name' => $l->name->value,
            'kind' => $l->kind->value,
            'kind_label' => $l->kind->label(),
            'active' => $l->active,
        ];

        return new JsonResponse([
            'locations' => array_map(
                static fn (Location $l): array => [...$location($l), 'children' => array_map($location, $l->children)],
                $locations,
            ),
            'device_types' => array_map(
                static fn ($t): array => ['id' => $t->id, 'name' => $t->name->value, 'active' => $t->active],
                $types,
            ),
        ]);
    }

    private static function http(RoleException $e): HttpException
    {
        return match (true) {
            $e instanceof InvalidRoleValueException => HttpException::invalidRequest($e->getMessage()),
            $e instanceof RoleNotFoundException => HttpException::notFound(),
            $e instanceof DuplicateRoleNameException => HttpException::nameTaken($e->getMessage()),
            $e instanceof RoleVersionConflictException => HttpException::versionConflict(
                $e->getMessage(),
                ['role' => RoleJson::role($e->current, $e->usage)],
            ),
            $e instanceof RoleRuleViolationException => HttpException::ruleViolation($e->getMessage(), $e->details),
            default => throw new \LogicException(sprintf('Unbekannter Fehler: %s', $e::class), previous: $e),
        };
    }
}
