<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Roles;

use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Restriction;
use FirefightingEquipment\Role\InvalidRoleValueException;
use FirefightingEquipment\Role\RoleDraft;

/**
 * Bodys der Rollenverwaltung. Ganzer Stand einer Rolle: {name, description?, areas {bereich: read|
 * write}, actions [...], locations, device_types} (Datenfilter null = alle, sonst Liste der IDs, nicht
 * leer). Unbekannte Felder, Bereiche, Stufen und Aktionen → 400.
 */
final class RoleFields
{
    /** Höchstens so viele IDs je Datenfilter (eine Feuerwehr hat wenige Standorte und Typen). */
    public const int MAX_FILTER_IDS = 500;

    private const array DRAFT_FIELDS = ['name', 'description', 'areas', 'actions', 'locations', 'device_types', 'restrictions'];

    private function __construct()
    {
    }

    /**
     * @param list<string> $extra weitere erlaubte Felder (z. B. version)
     *
     * @throws HttpException 400 invalid_request
     */
    public static function draft(RequestContext $context, array $extra = []): RoleDraft
    {
        DeviceFields::rejectUnknown($context, [...self::DRAFT_FIELDS, ...$extra]);
        foreach (['areas', 'actions', 'locations', 'device_types'] as $required) {
            if (!$context->hasField($required)) {
                throw HttpException::invalidRequest(sprintf('Das Feld "%s" fehlt.', $required));
            }
        }

        try {
            return RoleDraft::of(
                $context->stringField('name'),
                $context->optionalStringField('description'),
                self::areas($context->body['areas']),
                self::actions($context->body['actions']),
                self::ids($context->body['locations'], 'locations'),
                self::ids($context->body['device_types'], 'device_types'),
                self::restrictions($context->body['restrictions'] ?? []),
            );
        } catch (InvalidRoleValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }

    /**
     * Optionaler Name (Kopie, Übernahme); fehlt er oder ist er null, dann null.
     *
     * @throws HttpException 400 invalid_request
     */
    public static function optionalName(RequestContext $context): ?string
    {
        $name = $context->optionalStringField('name');
        try {
            return $name === null ? null : RoleDraft::name($name);
        } catch (InvalidRoleValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }

    /**
     * @return array<string, AreaLevel>
     */
    private static function areas(mixed $value): array
    {
        // {} kommt als leeres Array an.
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw HttpException::invalidRequest('Das Feld "areas" muss ein Objekt Bereich → Stufe sein.');
        }
        $areas = [];
        foreach ($value as $key => $level) {
            $area = Area::tryFrom((string) $key)
                ?? throw HttpException::invalidRequest(sprintf('Unbekannter Bereich „%s“.', (string) $key));
            $areas[$area->value] = (is_string($level) ? AreaLevel::tryFrom($level) : null)
                ?? throw HttpException::invalidRequest(sprintf('Die Stufe für „%s“ muss "read" oder "write" sein.', $area->label()));
        }

        return $areas;
    }

    /**
     * @return list<Action>
     */
    private static function actions(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpException::invalidRequest('Das Feld "actions" muss eine Liste sein.');
        }

        return array_map(
            static fn (mixed $action): Action => (is_string($action) ? Action::tryFrom($action) : null)
                ?? throw HttpException::invalidRequest(sprintf('Unbekannte Aktion „%s“.', is_scalar($action) ? (string) $action : '?')),
            $value,
        );
    }

    /**
     * Einschränkungen (Phase 2); das Feld darf fehlen (alte Apps, wie keine).
     *
     * @return bool Einschränkung „Bei Einmalgeräten nur benutzt melden“
     */
    private static function restrictions(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw HttpException::invalidRequest('Das Feld "restrictions" muss eine Liste sein.');
        }
        $keys = [];
        foreach ($value as $key) {
            $restriction = (is_string($key) ? Restriction::tryFrom($key) : null)
                ?? throw HttpException::invalidRequest(sprintf('Unbekannte Einschränkung „%s“.', is_scalar($key) ? (string) $key : '?'));
            $keys[] = $restriction;
        }

        return in_array(Restriction::SingleUseUsedOnly, $keys, true);
    }

    /**
     * @return list<int>|null
     */
    private static function ids(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_FILTER_IDS) {
            throw HttpException::invalidRequest(sprintf(
                'Das Feld "%s" muss null (alle) oder eine Liste mit höchstens %d IDs sein.',
                $field,
                self::MAX_FILTER_IDS,
            ));
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                throw HttpException::invalidRequest(sprintf('Das Feld "%s" darf nur positive ganze Zahlen enthalten.', $field));
            }
        }

        /** @var list<int> $value */
        return $value;
    }
}
