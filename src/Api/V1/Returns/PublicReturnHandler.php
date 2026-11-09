<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Returns;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\PublicReturnThrottle;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\Loan\LoanRepository;
use FirefightingEquipment\Device\Loan\ReturnKey;
use FirefightingEquipment\Device\Loan\ReturnPointRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\PersonName;

/**
 * Rückgabe ohne Anmeldung (Phase 2, P2.6; `Access::Public`, nur unter Städten, keine Sitzung, kein Cookie).
 * Der geheime Schlüssel des Aushangs gibt NUR die Rückgabe an dem einen Standort mit der Aufgabe „Rückgabe“
 * frei, nichts sonst:
 * - POST returns/check {key, code}: findet die verliehene Ausrüstung zu Nummer oder Scan-Code und nennt nur
 *   Nummer und Typ ({identifier, device_type});
 * - POST returns {key, code, name, condition (ok|used|defective), comment?}: bucht sie in die Rückgabe, beendet
 *   den Verleih und trägt „<Name> (ohne Anmeldung)“ als Urheber in den Verlauf ein. condition `ok` lässt den
 *   Status, `used`/`defective` setzt ihn. Antwort {identifier, device_type, location, status_label}.
 * Ungültiger Schlüssel oder Code → 400 `invalid_request` (zählt in die Bremse `public_return`, 20 je
 * IP-Präfix und 15 Minuten, sonst 429); Verlauf und Ausrüstung bleiben unberührt.
 */
final readonly class PublicReturnHandler implements RequestHandler
{
    public function __construct(private bool $check)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $keyValue = $context->stringField('key');
        $code = $context->stringField('code');
        $name = null;
        $condition = 'ok';
        $comment = null;
        if (!$this->check) {
            DeviceFields::rejectUnknown($context, ['key', 'code', 'name', 'condition', 'comment']);
            $nameValue = $context->stringField('name');
            $condition = $context->optionalStringField('condition') ?? 'ok';
            if (!in_array($condition, ['ok', 'used', 'defective'], true)) {
                throw HttpException::invalidRequest('Das Feld "condition" muss "ok", "used" oder "defective" sein.');
            }
            try {
                $name = PersonName::fromString($nameValue)->value;
                $comment = DeviceText::comment($context->optionalStringField('comment'));
            } catch (InvalidUserValueException | DeviceException $e) {
                throw HttpException::invalidRequest($e->getMessage());
            }
        } else {
            DeviceFields::rejectUnknown($context, ['key', 'code']);
        }

        $pdo = $context->db->get();
        $throttle = new PublicReturnThrottle($pdo);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->retryAfter($ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $key = ReturnKey::fromInput($keyValue);
        $place = $key === null ? null : new ReturnPointRepository($pdo)->locationFor($tenant, $key);
        if ($place === null) {
            $throttle->record($tenant->id, $ip, false);
            throw HttpException::invalidRequest('Dieser Rückgabe-Link gilt nicht (mehr). Bitte den aktuellen Aushang verwenden.');
        }
        $loans = new LoanRepository($pdo);
        $found = $loans->findLoaned($tenant, $code);
        if ($found === null) {
            $throttle->record($tenant->id, $ip, false);
            throw HttpException::invalidRequest('Diese Ausrüstung ist nicht verliehen. Bitte Nummer prüfen.');
        }

        if ($this->check) {
            $throttle->record($tenant->id, $ip, true);

            return new JsonResponse(['identifier' => $found['identifier'], 'device_type' => $found['device_type'], 'location' => $place['name']]);
        }

        $devices = new DeviceRepository($pdo, DeviceVisibility::all(), null, $context->channel());
        $status = match ($condition) {
            'used' => DeviceStatus::Used,
            'defective' => DeviceStatus::Defective,
            default => null,
        };
        try {
            $device = $devices->returnLoaned(
                $tenant,
                $found['id'],
                $place['id'],
                $status,
                (string) $name,
                $comment,
                $loans->returning($tenant),
            );
        } catch (DeviceException $e) {
            $throttle->record($tenant->id, $ip, false);
            throw DeviceErrors::http($e);
        }
        $throttle->record($tenant->id, $ip, true);

        return new JsonResponse([
            'identifier' => $device->identifier->value,
            'device_type' => $device->deviceTypeName,
            'location' => $place['name'],
            'status_label' => $device->status->label(),
        ]);
    }
}
