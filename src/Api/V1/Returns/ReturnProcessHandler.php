<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Returns;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceFilter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceSort;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Device\SortDirection;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;

/**
 * „Rückgaben bearbeiten“ (Bereich `loans` Ändern, Phase 2, P2.6): Ausrüstung, die in der Rückgabe steht
 * (Standort mit der Aufgabe „Rückgabe“ samt Fächern), geht weiter. Body {devices: [{id, version}], comment?},
 * höchstens DeviceBulkHandler::MAX_DEVICES; Antwort und Teilerfolg wie die Sammelaktionen der Liste plus
 * `target`. Das Ziel gibt der Ablauf vor und gilt auch außerhalb eines Datenfilters:
 * - ToCollection: benutzte Ausrüstung in die Sammelstelle, die benutzte annimmt, defekte in die, die defekte
 *   annimmt (der Status bleibt); einsatzbereite oder in Wartung gehören ins Lager (Fehler des Eintrags);
 * - ToStore: ins Standard-Lager, Status einsatzbereit.
 * Ausrüstung außerhalb der Rückgabe ist ein Fehler des Eintrags. Fehlt das Standard-Lager: 409 rule_violation
 * vor jeder Änderung (ToStore); fehlt die Sammelstelle, ist es ein Fehler der betroffenen Einträge.
 */
final readonly class ReturnProcessHandler implements RequestHandler
{
    public function __construct(private ReturnProcess $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $user = $context->auth()->user;
        $pdo = $context->db->get();
        $recorder = new DeviceEventRecorder();
        $devices = new DeviceRepository($pdo, $context->visibility(), $recorder, $context->channel());
        $locations = new LocationRepository($pdo, $context->visibility());

        $entries = [];
        $seen = [];
        try {
            foreach (DeviceFields::bulkEntries($context, DeviceBulkHandler::MAX_DEVICES) as $index => $entry) {
                try {
                    DeviceFields::rejectUnknown($entry, ['id', 'version']);
                    $id = DeviceFields::deviceId($entry);
                    $entries[] = [$id, DeviceFields::version($entry)];
                } catch (HttpException | DeviceException $e) {
                    throw HttpException::invalidRequest(sprintf('Eintrag %d in "devices": %s', $index + 1, $e->getMessage()));
                }
                if (isset($seen[$id])) {
                    throw HttpException::invalidRequest(sprintf('Die Ausrüstung #%d steht mehrfach in "devices".', $id));
                }
                $seen[$id] = true;
            }
            $comment = DeviceFields::comment($context);
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        $store = $locations->withTask($tenant, LocationTask::Store, defaultStoreOnly: true)[0] ?? null;
        if ($this->action === ReturnProcess::ToStore && $store === null) {
            throw HttpException::ruleViolation('Es gibt kein Standard-Lager. Bitte zuerst bei einem Standort die Aufgabe „Lager“ vergeben.');
        }

        // Was in der Rückgabe steht (Standorte mit der Aufgabe samt Fächern).
        $inReturns = [];
        foreach ($locations->withTask($tenant, LocationTask::Returns) as $place) {
            $page = $devices->list($tenant, new DeviceFilter(locationId: $place['id'], includeSublocations: true), DeviceSort::Identifier, SortDirection::Asc, 200, 0);
            foreach ($page->devices as $device) {
                $inReturns[$device->id] = true;
            }
        }

        $results = [];
        $changed = [];
        $counts = ['applied' => 0, 'unchanged' => 0, 'errors' => 0];
        $target = null;
        foreach ($entries as [$id, $version]) {
            $recorder->reset();
            try {
                $current = $devices->find($tenant, $id) ?? throw DeviceErrors::http(\FirefightingEquipment\Device\DeviceNotFoundException::forId($id));
                if (!isset($inReturns[$id])) {
                    throw HttpException::ruleViolation(sprintf('%s steht nicht in der Rückgabe.', $current->identifier->value));
                }
                if ($this->action === ReturnProcess::ToStore) {
                    $target = $store;
                    $change = new DeviceStateChange($store['id'] ?? 0, DeviceStatus::Ready, true);
                } else {
                    $place = match ($current->status) {
                        DeviceStatus::Used => $locations->collectionPointFor($tenant, 'used'),
                        DeviceStatus::Defective => $locations->collectionPointFor($tenant, 'defective'),
                        default => throw HttpException::ruleViolation(sprintf('%s ist weder benutzt noch defekt und gehört ins Lager.', $current->identifier->value)),
                    };
                    if ($place === null) {
                        throw HttpException::ruleViolation($current->status === DeviceStatus::Used
                            ? 'Es gibt keine Sammelstelle für benutzte Ausrüstung.'
                            : 'Es gibt keine Sammelstelle für defekte Ausrüstung.');
                    }
                    $target = $place;
                    $change = new DeviceStateChange($place['id'], null, true);
                }
                $result = $devices->book($tenant, $user->id, $id, $version, $change, $comment);
            } catch (HttpException | DeviceException $e) {
                $error = $e instanceof HttpException ? $e : DeviceErrors::http($e);
                $results[] = DeviceBulkHandler::result($id, 'error', false, $devices->find($tenant, $id), $error);
                ++$counts['errors'];
                continue;
            }
            if ($recorder->ids() !== []) {
                $changed[] = [$result->device, $recorder->ids(), $recorder->before()];
            }
            $results[] = DeviceBulkHandler::result($id, $result->outcome->value, false, $result->device, null);
            ++$counts[$result->outcome === BookingOutcome::Applied ? 'applied' : 'unchanged'];
        }

        if ($changed !== []) {
            DeviceLogNotifier::fromContext($context)->bulkChanged($tenant, $user, $changed);
        }

        return new JsonResponse(['results' => $results, 'summary' => ['total' => count($results), ...$counts], 'target' => $target]);
    }
}
