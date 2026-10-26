<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceNotFoundException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Gerät anzeigen, anlegen bzw. ändern, siehe DeviceAction. Antwort {"device": …} mit allen Feldern
 * und dem Stand danach, beim Anlegen 201.
 *
 * Ungültige Felder 400 (auch ein unbekannter oder fremder Gerätetyp bzw. Standort im Body), fremde
 * oder unbekannte ID im Pfad 404, Kennung vergeben 409 identifier_taken, veraltete Version 409
 * version_conflict (details {"device": aktueller Stand}), Regelverstoß 409 rule_violation (Standort
 * gesperrt, Gerätetyp deaktiviert, Gerät ausgesondert bzw. beim Wiederaufnehmen nicht ausgesondert).
 * Felder werden vor der Suche gelesen (400 vor 404). Ändert sich nichts, bleibt die Version gleich.
 */
final readonly class DeviceActionHandler implements RequestHandler
{
    public function __construct(private DeviceAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $devices = new DeviceRepository($context->db->get());

        try {
            $device = match ($this->action) {
                DeviceAction::Show => $devices->find($tenant, $context->param('id'))
                    ?? throw DeviceNotFoundException::forId($context->param('id')),
                DeviceAction::Create => $devices->create(
                    $tenant,
                    $context->auth()->user->id,
                    DeviceFields::newDevice($context),
                    DeviceFields::comment($context),
                ),
                DeviceAction::UpdateFields => $devices->updateFields(
                    $tenant,
                    $context->auth()->user->id,
                    $context->param('id'),
                    DeviceFields::version($context),
                    DeviceFields::changes($context),
                    DeviceFields::comment($context),
                ),
                DeviceAction::SetInspectionDue => $devices->setInspectionDue(
                    $tenant,
                    $context->auth()->user->id,
                    $context->param('id'),
                    DeviceFields::version($context),
                    DeviceFields::inspectionDue($context),
                    DeviceFields::comment($context),
                ),
                DeviceAction::ChangeState => $devices->changeState(
                    $tenant,
                    $context->auth()->user->id,
                    $context->param('id'),
                    DeviceFields::version($context),
                    DeviceFields::stateChange($context),
                    DeviceFields::comment($context),
                ),
                DeviceAction::Retire => $devices->retire(
                    $tenant,
                    $context->auth()->user->id,
                    $context->param('id'),
                    DeviceFields::version($context),
                    DeviceFields::retirementComment($context),
                ),
                DeviceAction::Reinstate => $devices->reinstate(
                    $tenant,
                    $context->auth()->user->id,
                    $context->param('id'),
                    DeviceFields::version($context),
                    DeviceFields::reinstatement($context),
                    DeviceFields::comment($context),
                ),
            };
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['device' => DeviceJson::device($device)], $this->action === DeviceAction::Create ? 201 : 200);
    }
}
