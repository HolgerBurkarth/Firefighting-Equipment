<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\DeviceTypes;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceTypeName;
use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Gerätetypen verwalten (Admin), siehe DeviceTypeAction. Antwort {"device_type": …} mit dem Stand
 * danach, beim Anlegen 201. Ungültiger Name 400, fremde oder unbekannte ID 404, Name schon vergeben
 * 409 name_taken. Deaktivieren und Aktivieren sind wiederholbar; bestehende Geräte behalten ihren Typ.
 */
final readonly class DeviceTypeActionHandler implements RequestHandler
{
    public function __construct(private DeviceTypeAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $types = new DeviceTypeRepository($context->db->get());

        try {
            // Felder vor der Suche des Typs lesen (400 vor 404).
            $type = match ($this->action) {
                DeviceTypeAction::Create => $types->create($tenant, self::name($context)),
                DeviceTypeAction::Rename => $types->rename($tenant, $context->param('id'), self::name($context)),
                DeviceTypeAction::Deactivate => $types->setActive($tenant, $context->param('id'), false),
                DeviceTypeAction::Activate => $types->setActive($tenant, $context->param('id'), true),
            };
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['device_type' => DeviceTypeJson::deviceType($type)], $this->action === DeviceTypeAction::Create ? 201 : 200);
    }

    /**
     * @throws \FirefightingEquipment\Http\HttpException 400 invalid_request (Feld fehlt)
     * @throws DeviceException                           Name verletzt die Regeln
     */
    private static function name(RequestContext $context): DeviceTypeName
    {
        return DeviceTypeName::fromString($context->stringField('name'));
    }
}
