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
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use FirefightingEquipment\Offline\ActionTime;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\GermanFormat;
use FirefightingEquipment\Time\SystemClock;

/**
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
    ) {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $userId = $context->auth()->user->id;
        $deviceId = $context->param('id');
        $pdo = $context->db->get();
        $recorder = new DeviceEventRecorder();
        $booker = new DeviceBooker($tenant, new DeviceRepository($pdo, $recorder), new DeviceBookingRepository($pdo), new DeviceNoticeRepository($pdo));

        try {
            $version = DeviceFields::version($context);
            $change = DeviceFields::stateChange($context);
            $comment = DeviceFields::comment($context);
            [$clientRef, $occurredAt, $confirmedStale] = DeviceFields::bookingFields($context);
            $hash = BookingRequest::hash($deviceId, $version, $change, $comment, $occurredAt);

            $previous = $booker->previous($clientRef, $userId, $deviceId, $hash);
            if ($previous !== null) {
                return self::response($previous);
            }

            $stale = $occurredAt !== null && $this->checkActionTime($occurredAt, $confirmedStale);
            $result = $booker->book($userId, $deviceId, $version, $change, $comment, new BookingRequest($clientRef, $occurredAt, $stale, $hash));
            if ($result->replayed) {
                // Dieselbe client_ref gleichzeitig verarbeitet: Die andere Anfrage hat gespeichert und benachrichtigt.
                return self::response($result);
            }
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        // Nach dem Commit: Hinweis-Mail (ersetzt die Mail zum Vorgang) bzw. Benachrichtigung „Log“.
        if ($result->noticeId !== null) {
            DeviceLogNotifier::fromContext($context)->notice($tenant, $context->auth()->user, $result->noticeId, new DeviceNoticeRepository($pdo));
        } elseif ($recorder->ids() !== []) {
            DeviceLogNotifier::fromContext($context)->deviceChanged($tenant, $context->auth()->user, $result->device, $recorder->ids());
        }

        return self::response($result);
    }

    /**
     * Prüft die Aktionszeit einer Offline-Buchung gegen die Serverzeit: mehr als
     * MAX_CLOCK_SKEW_SECONDS in der Zukunft → 422 clock_implausible (genau die Grenze ist erlaubt);
     * älter als offline.queue_max_age_days (genau die Grenze ist erlaubt) ohne confirmed_stale →
     * 409 stale_action.
     *
     * @return bool alte Buchung, nach Rückfrage gesendet
     *
     * @throws HttpException
     */
    private function checkActionTime(ActionTime $occurredAt, bool $confirmedStale): bool
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $maxSkew = OfflineSettings::MAX_CLOCK_SKEW_SECONDS;
        if ($occurredAt->value->getTimestamp() - $now->getTimestamp() > $maxSkew) {
            throw HttpException::clockImplausible(
                sprintf(
                    'Die Aktionszeit der Buchung (%s) liegt mehr als %d Minuten in der Zukunft. Bitte Datum und Uhrzeit des Geräts prüfen.',
                    GermanFormat::dateTime($occurredAt->value),
                    intdiv($maxSkew, 60),
                ),
                ['server_time' => ActionTime::format($now), 'occurred_at' => ActionTime::format($occurredAt->value), 'max_skew_seconds' => $maxSkew],
            );
        }

        $days = $this->offline->config()->queueMaxAgeDays;
        if ($occurredAt->value >= $now->modify(sprintf('-%d days', $days))) {
            return false;
        }
        if (!$confirmedStale) {
            throw HttpException::staleAction(
                sprintf(
                    'Die Buchung ist älter als %d Tage (Aktionszeit %s). Soll sie trotzdem gesendet werden? Dann erneut mit "confirmed_stale": true senden.',
                    $days,
                    GermanFormat::dateTime($occurredAt->value),
                ),
                ['occurred_at' => ActionTime::format($occurredAt->value), 'queue_max_age_days' => $days, 'server_time' => ActionTime::format($now)],
            );
        }

        return true;
    }

    private static function response(BookingResult $result): JsonResponse
    {
        return new JsonResponse([
            'device' => DeviceJson::device($result->device),
            'result' => DeviceBookingJson::result($result),
        ]);
    }
}
