<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\ClientRef;
use FirefightingEquipment\Device\Booking\DeviceBooker;
use FirefightingEquipment\Device\Booking\DeviceBookingRepository;
use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;

/**
 * Sammelaktionen der Geräteliste (Admin, KONZEPT.md Entscheidung 31), siehe DeviceBulkAction:
 * höchstens MAX_DEVICES Geräte je Anfrage, je Gerät id, version und die Zielwerte, dazu ein
 * gemeinsamer Kommentar. Jedes Gerät wird einzeln geprüft und in einer eigenen Transaktion geändert
 * (eigener Eintrag im Verlauf, Regeln wie POST devices/{id}/inspection-due bzw. …/state ohne
 * Aktionszeit); Teilerfolg ist erlaubt.
 *
 * Antwort immer 200 {"results": [{id, outcome, replayed, device, error}], "summary": {total,
 * applied, unchanged, errors}} in der Reihenfolge der Anfrage: outcome applied, unchanged oder error;
 * device der Stand danach (bei error der aktuelle, null bei fremder oder unbekannter ID); error null
 * bzw. {error, message, details?} wie die Antwort der einzelnen Route (z. B. version_conflict,
 * rule_violation, not_found). replayed: dieselbe client_ref wurde schon verarbeitet (nur bei state).
 *
 * Fehler der ganzen Anfrage (400, vor jeder Änderung): Felder ungültig (Meldung mit der Nummer des
 * Eintrags), mehr als MAX_DEVICES oder kein Gerät, ein Gerät mehrfach.
 *
 * Nach den Änderungen geht EINE Sammelmail „Log“ je Empfänger (DeviceLogNotifier::bulkChanged),
 * nur wenn Geräte geändert wurden.
 */
final readonly class DeviceBulkHandler implements RequestHandler
{
    /** Höchstzahl der Geräte je Anfrage; die App teilt größere Auswahlen in Blöcke. */
    public const int MAX_DEVICES = 50;

    public function __construct(private DeviceBulkAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $user = $context->auth()->user;
        $pdo = $context->db->get();
        $recorder = new DeviceEventRecorder();
        $devices = new DeviceRepository($pdo, $recorder);
        $booker = new DeviceBooker($tenant, $devices, new DeviceBookingRepository($pdo), new DeviceNoticeRepository($pdo));

        $entries = $this->entries($context);
        try {
            $comment = DeviceFields::comment($context);
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        $results = [];
        $changed = [];
        $counts = ['applied' => 0, 'unchanged' => 0, 'errors' => 0];
        foreach ($entries as $entry) {
            $id = $entry['id'];
            $recorder->reset();
            try {
                if ($this->action === DeviceBulkAction::InspectionDue) {
                    $device = $devices->setInspectionDue($tenant, $user->id, $id, $entry['version'], $entry['due'], $comment);
                    [$outcome, $replayed] = [$recorder->ids() === [] ? BookingOutcome::Unchanged : BookingOutcome::Applied, false];
                } else {
                    [$device, $outcome, $replayed] = $this->book($booker, $user->id, $entry, $comment);
                }
            } catch (DeviceException $e) {
                $error = DeviceErrors::http($e);
                $results[] = self::result($id, 'error', false, $devices->find($tenant, $id), $error);
                ++$counts['errors'];
                continue;
            }

            if (!$replayed && $recorder->ids() !== []) {
                $changed[] = [$device, $recorder->ids()];
            }
            $results[] = self::result($id, $outcome->value, $replayed, $device, null);
            ++$counts[$outcome === BookingOutcome::Applied ? 'applied' : 'unchanged'];
        }

        if ($changed !== []) {
            DeviceLogNotifier::fromContext($context)->bulkChanged($tenant, $user, $changed);
        }

        return new JsonResponse(['results' => $results, 'summary' => ['total' => count($results), ...$counts]]);
    }

    /**
     * Liest alle Einträge, bevor etwas geändert wird.
     *
     * @return list<array{id: int, version: int, due: ?DeviceDate, change: ?DeviceStateChange, clientRef: ?ClientRef}>
     *
     * @throws HttpException 400 invalid_request
     */
    private function entries(RequestContext $context): array
    {
        $entries = [];
        $seen = [];
        foreach (DeviceFields::bulkEntries($context, self::MAX_DEVICES) as $index => $entry) {
            try {
                $id = DeviceFields::deviceId($entry);
                $version = DeviceFields::version($entry);
                [$due, $change, $clientRef] = match ($this->action) {
                    DeviceBulkAction::InspectionDue => [DeviceFields::bulkInspectionDue($entry), null, null],
                    DeviceBulkAction::State => [null, ...DeviceFields::bulkStateChange($entry)],
                };
            } catch (HttpException | DeviceException $e) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "devices": %s', $index + 1, $e->getMessage()));
            }
            if (isset($seen[$id])) {
                throw HttpException::invalidRequest(sprintf('Das Gerät #%d steht mehrfach in "devices".', $id));
            }
            $seen[$id] = true;
            $entries[] = ['id' => $id, 'version' => $version, 'due' => $due, 'change' => $change, 'clientRef' => $clientRef];
        }

        return $entries;
    }

    /**
     * Umbuchen bzw. Status wie POST devices/{id}/state ohne Aktionszeit, mit Wiederholung über die
     * client_ref.
     *
     * @param array{id: int, version: int, change: ?DeviceStateChange, clientRef: ?ClientRef} $entry
     *
     * @return array{Device, BookingOutcome, bool}
     *
     * @throws DeviceException
     */
    private function book(DeviceBooker $booker, int $userId, array $entry, ?string $comment): array
    {
        $change = $entry['change'] ?? throw new \LogicException('Zielwerte fehlen.');
        $hash = BookingRequest::hash($entry['id'], $entry['version'], $change, $comment, null);
        $result = $booker->previous($entry['clientRef'], $userId, $entry['id'], $hash)
            ?? $booker->book($userId, $entry['id'], $entry['version'], $change, $comment, new BookingRequest($entry['clientRef'], null, false, $hash));

        return [$result->device, $result->outcome, $result->replayed];
    }

    /**
     * @return array<string, mixed>
     */
    private static function result(int $id, string $outcome, bool $replayed, ?Device $device, ?HttpException $error): array
    {
        return [
            'id' => $id,
            'outcome' => $outcome,
            'replayed' => $replayed,
            'device' => $device === null ? null : DeviceJson::device($device),
            'error' => $error === null ? null : [
                'error' => $error->errorCode,
                'message' => $error->getMessage(),
                ...($error->details === null ? [] : ['details' => $error->details]),
            ],
        ];
    }
}
