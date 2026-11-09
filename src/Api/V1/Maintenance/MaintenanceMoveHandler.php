<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Maintenance;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;

/**
 * Sammelaktionen der Seite „Wartung“ (Bereich `maintenance` Ändern, Phase 2, P2.4, G10), siehe
 * MaintenanceMove: je Gerät {id, version}, dazu ein gemeinsamer Kommentar, höchstens
 * DeviceBulkHandler::MAX_DEVICES. Das Ziel gibt der Ablauf vor und gilt auch außerhalb eines
 * Datenfilters (G4): „Zur Werkstatt“ → der erste aktive Standort mit der Aufgabe Werkstatt, Status in
 * Wartung (Einmalgeräte werden nicht gewartet: Fehler rule_violation); „Zurück“ → das Standard-Lager,
 * Status einsatzbereit. Gibt es das Ziel nicht, 409 rule_violation vor jeder Änderung.
 *
 * Antwort und Teilerfolg wie DeviceBulkHandler (results, summary; je Gerät eine eigene Transaktion mit
 * Version, eigener Verlauf), danach eine Sammelmail „Log“.
 */
final readonly class MaintenanceMoveHandler implements RequestHandler
{
    public function __construct(private MaintenanceMove $action)
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

        $toWorkshop = $this->action === MaintenanceMove::ToWorkshop;
        $target = $toWorkshop
            ? $locations->withTask($tenant, LocationTask::Workshop)[0] ?? null
            : $locations->withTask($tenant, LocationTask::Store, defaultStoreOnly: true)[0] ?? null;
        if ($target === null) {
            throw HttpException::ruleViolation($toWorkshop
                ? 'Es gibt keine Werkstatt. Bitte zuerst bei einem Standort die Aufgabe „Werkstatt“ vergeben.'
                : 'Es gibt kein Standard-Lager. Bitte zuerst bei einem Standort die Aufgabe „Lager“ vergeben.');
        }
        $change = new DeviceStateChange($target['id'], $toWorkshop ? DeviceStatus::Maintenance : DeviceStatus::Ready, true);

        $results = [];
        $changed = [];
        $counts = ['applied' => 0, 'unchanged' => 0, 'errors' => 0];
        foreach ($entries as [$id, $version]) {
            $recorder->reset();
            try {
                if ($toWorkshop && $devices->find($tenant, $id)?->deviceTypeSingleUse === true) {
                    throw HttpException::ruleViolation('Einmalgeräte werden nicht gewartet, sondern ersetzt.');
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
