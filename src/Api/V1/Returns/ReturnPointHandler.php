<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Returns;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\Loan\ReturnPointRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Rückgabestellen verwalten (Bereich `loans` Ändern, Phase 2, P2.6; G16):
 * - POST return-points/{id}/renew {}: neuer geheimer Schlüssel für den Standort mit der Aufgabe „Rückgabe“,
 *   der alte gilt nicht mehr. Antwort {key} – der Klartext steht NUR in dieser Antwort (in der Datenbank nur
 *   der Hash), die App macht daraus Link und QR-Code des Aushangs;
 * - POST return-points/{id}/revoke {}: widerruft den Schlüssel (wiederholbar), Antwort {revoked: true}.
 * Fremde oder unbekannte Standorte → 404, Standort ohne die Aufgabe → 409 rule_violation.
 */
final readonly class ReturnPointHandler implements RequestHandler
{
    public function __construct(private bool $renew)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $id = $context->param('id');
        $points = new ReturnPointRepository($context->db->get());

        try {
            if ($this->renew) {
                return new JsonResponse(['key' => $points->renew($tenant, $id, $context->auth()->user->id)->value]);
            }
            $points->revoke($tenant, $id);
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['revoked' => true]);
    }
}
