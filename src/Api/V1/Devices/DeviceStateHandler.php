<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\BookingResult;
use FirefightingEquipment\Device\Booking\DeviceBooker;
use FirefightingEquipment\Device\Booking\DeviceBookingRepository;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\SingleUseGuard;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use FirefightingEquipment\Offline\ActionTimeGuard;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;

/**
 * POST api/v1/devices/{id}/used {version, comment?, client_ref?, occurred_at?, confirmed_stale?} (Phase 2,
 * P2.3, Aktion device.used): „Benutzt melden“. Status benutzt; gibt es eine Sammelstelle, die benutzte
 * Ausrüstung annimmt, ist sie das Ziel (auch außerhalb des Datenfilters, G4), sonst bleibt der Standort.
 * Sonst wie /state (Wiederholung, Aktionszeit, Antwort).
 *
 * POST api/v1/devices/{id}/state {version, location_id?, status?, comment?, client_ref?,
 * occurred_at?, confirmed_stale?} (angemeldet): Umbuchen und/oder Status setzen. Antwort
 * {"device": …, "result": {…}} (DeviceBookingJson), immer 200.
 *
 * Mit client_ref: Dieselbe Buchung erneut gesendet wirkt nicht noch einmal; die Antwort wiederholt
 * das gespeicherte Ergebnis (replayed true) mit dem aktuellen Stand. Dieselbe client_ref mit anderem
 * Inhalt, Gerät oder Konto: 409 client_ref_conflict. Fehler wie DeviceActionHandler (400, 404, 409).
 *
 * Mit occurred_at (Offline-Buchung, nur mit client_ref): Reihenfolge Felder (400) → Wiederholung →
 * Aktionszeit (422 clock_implausible, 409 stale_action) → Buchung nach DeviceRepository::book()
 * („der Letzte gewinnt“; ausgesondert, gesperrter Standort und veraltete Version ohne 409).
 */
final readonly class DeviceStateHandler implements RequestHandler
{
    public function __construct(
        private Clock $clock = new SystemClock(),
        private OfflineSettings $offline = new OfflineSettings(),
        private bool $used = false,
    ) {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $userId = $context->auth()->user->id;
        $deviceId = $context->param('id');
        $pdo = $context->db->get();
        $recorder = new DeviceEventRecorder();
        $devices = new DeviceRepository($pdo, $context->visibility(), $recorder, $context->channel());
        $booker = new DeviceBooker($tenant, $devices, new DeviceBookingRepository($pdo), new DeviceNoticeRepository($pdo, $context->visibility()));

        try {
            $version = DeviceFields::version($context);
            if ($this->used) {
                DeviceFields::rejectUnknown($context, ['version', 'comment', 'client_ref', 'occurred_at', 'confirmed_stale', 'confirmed_below_minimum']);
                $change = new DeviceStateChange(new LocationRepository($pdo, $context->visibility())->collectionPointForUsed($tenant), DeviceStatus::Used, true, stock: DeviceFields::stockMode($context));
            } else {
                $change = DeviceFields::stateChange($context);
                SingleUseGuard::check($context->permissions(), $devices, $tenant, $deviceId);
            }
            $comment = DeviceFields::comment($context);
            [$clientRef, $occurredAt, $confirmedStale] = DeviceFields::bookingFields($context);
            $hash = BookingRequest::hash($deviceId, $version, $change, $comment, $occurredAt);
            $onlineHash = BookingRequest::onlineHash($deviceId, $version, $change, $comment, $occurredAt);

            $previous = $booker->previous($clientRef, $userId, $deviceId, $hash, $onlineHash);
            if ($previous !== null) {
                return self::response($previous);
            }

            $stale = $occurredAt !== null && new ActionTimeGuard($this->clock, $this->offline)->check($occurredAt, $confirmedStale);
            $result = $booker->book($userId, $deviceId, $version, $change, $comment, new BookingRequest($clientRef, $occurredAt, $stale, $hash, $onlineHash));
            if ($result->replayed) {
                // Dieselbe client_ref gleichzeitig verarbeitet: Die andere Anfrage hat gespeichert und benachrichtigt.
                return self::response($result);
            }
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        // Nach dem Commit: Hinweis-Mail (ersetzt die Mail zum Vorgang) bzw. Benachrichtigung „Log“.
        if ($result->noticeId !== null) {
            DeviceLogNotifier::fromContext($context)->notice($tenant, $context->auth()->user, $result->noticeId);
        } elseif ($recorder->ids() !== []) {
            DeviceLogNotifier::fromContext($context)->deviceChanged($tenant, $context->auth()->user, $result->device, $recorder->ids(), $recorder->before());
        }

        return self::response($result);
    }

    private static function response(BookingResult $result): JsonResponse
    {
        return new JsonResponse([
            'device' => DeviceJson::device($result->device),
            'result' => DeviceBookingJson::result($result),
        ]);
    }
}
