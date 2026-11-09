<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\Booking\BookingOutcome;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\BookingResult;
use FirefightingEquipment\Device\Booking\DeviceBooker;
use FirefightingEquipment\Device\Booking\DeviceBookingRepository;
use FirefightingEquipment\Device\Booking\DuplicateClientRefException;
use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Offline\ActionTimeGuard;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;

/**
 * POST api/v1/devices/swap (Aktion device.swap, Phase 2, P2.8): Tausch. Body {out: {id, version}, in: {id,
 * version}, out_status? (used|defective|maintenance, Standard used), out_location_id?, comment?,
 * confirmed_below_minimum?, client_ref?, occurred_at?, confirmed_stale?}. Die Ausrüstung „out“ geht heraus: in die Sammelstelle für benutzte (Status used)
 * bzw. defekte Ausrüstung, sonst in den angegebenen Standort oder sie bleibt (Wartung). Der Ersatz „in“ (muss
 * einsatzbereit sein) kommt an den bisherigen Standort von „out“. Beides in einer Transaktion, beide
 * Verlaufseinträge tragen dieselbe swap_id. Das Ziel der Sammelstelle gilt auch außerhalb des Datenfilters (G4).
 * Der Sollbestand wird über beide zusammen geprüft (StockMode, 409 rule_violation mit details.stock).
 * Antwort {out: Gerät, in: Gerät, swap_id}.
 */
final readonly class DeviceSwapHandler implements RequestHandler
{
    private const array OUT_STATUSES = [DeviceStatus::Used, DeviceStatus::Defective, DeviceStatus::Maintenance];

    public function __construct(
        private Clock $clock = new SystemClock(),
        private OfflineSettings $offline = new OfflineSettings(),
    ) {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $user = $context->auth()->user;
        $pdo = $context->db->get();
        $devices = new DeviceRepository($pdo, $context->visibility(), new DeviceEventRecorder(), $context->channel());

        try {
            DeviceFields::rejectUnknown($context, ['out', 'in', 'out_status', 'out_location_id', 'comment', 'confirmed_below_minimum', 'client_ref', 'occurred_at', 'confirmed_stale']);
            [$outId, $outVersion] = self::entry($context, 'out');
            [$inId, $inVersion] = self::entry($context, 'in');
            $status = DeviceStatus::tryFrom($context->optionalStringField('out_status') ?? DeviceStatus::Used->value);
            if ($status === null || !in_array($status, self::OUT_STATUSES, true)) {
                throw HttpException::invalidRequest('Das Feld "out_status" muss "used", "defective" oder "maintenance" sein.');
            }
            $locationId = $context->optionalIntField('out_location_id', 1, DeviceFields::MAX_ID);
            $process = false;
            if ($locationId === null && $status !== DeviceStatus::Maintenance) {
                $point = new LocationRepository($pdo, $context->visibility())->collectionPointFor($tenant, $status === DeviceStatus::Used ? 'used' : 'defective');
                $locationId = $point['id'] ?? null;
                $process = $locationId !== null;
            }
            $comment = DeviceFields::comment($context);
            $stock = DeviceFields::stockMode($context);
            $change = new DeviceStateChange($locationId, $status, $process);
            [$clientRef, $occurredAt, $confirmedStale] = DeviceFields::bookingFields($context);
            // Der Tausch wird am ausgehenden Gerät quittiert; der Ersatz gehört zum Inhalt (Hash).
            $content = ($comment ?? '') . "\x1f" . $inId . ':' . $inVersion;
            $hash = BookingRequest::hash($outId, $outVersion, $change, $content, $occurredAt);
            // Wie bei /state: Ein Tausch aus der Warteschlange wiederholt auch denselben online gesendeten ohne Aktionszeit.
            $onlineHash = BookingRequest::onlineHash($outId, $outVersion, $change, $content, $occurredAt);
            $booker = new DeviceBooker($tenant, $devices, new DeviceBookingRepository($pdo), new DeviceNoticeRepository($pdo, $context->visibility()));

            $previous = $booker->previous($clientRef, $user->id, $outId, $hash, $onlineHash);
            if ($previous !== null) {
                return $this->replayed($devices, $tenant, $previous, $inId);
            }

            $stale = $occurredAt !== null && new ActionTimeGuard($this->clock, $this->offline)->check($occurredAt, $confirmedStale);
            $booking = $clientRef === null ? null : new BookingRequest($clientRef, $occurredAt, $stale, $hash, $onlineHash);
            try {
                [$out, $in, $swapId, $eventId, $noticeId] = $devices->swap($tenant, $user->id, $outId, $outVersion, $inId, $inVersion, $change, $comment, $stock, $booking);
            } catch (DuplicateClientRefException) {
                // Dieselbe client_ref gleichzeitig verarbeitet: Die andere Anfrage hat gespeichert und benachrichtigt.
                $previous = $booker->previous($clientRef, $user->id, $outId, $hash, $onlineHash) ?? throw new \LogicException('Quittung fehlt.');

                return $this->replayed($devices, $tenant, $previous, $inId);
            }
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        $ids = [];
        $stmt = $pdo->prepare('SELECT id, device_id FROM fe_device_events WHERE tenant_id = ? AND swap_id = ? ORDER BY id');
        $stmt->execute([$tenant->id, $swapId]);
        foreach ($stmt->fetchAll() as $row) {
            $ids[(int) $row['device_id']][] = (int) $row['id'];
        }
        if ($noticeId !== null) {
            // Hinweis-Mail ersetzt die Mail zum Vorgang.
            DeviceLogNotifier::fromContext($context)->notice($tenant, $user, $noticeId);
        } else {
            DeviceLogNotifier::fromContext($context)->bulkChanged($tenant, $user, array_values(array_filter([
                isset($ids[$out->id]) ? [$out, $ids[$out->id], null] : null,
                isset($ids[$in->id]) ? [$in, $ids[$in->id], null] : null,
            ])));
        }

        $body = ['out' => DeviceJson::device($out), 'in' => DeviceJson::device($in), 'swap_id' => $swapId];
        if ($booking !== null) {
            $body['result'] = DeviceBookingJson::result(new BookingResult($out, BookingOutcome::Applied, null, $stale, $booking->clientRef, false, $eventId, $noticeId));
        }

        return new JsonResponse($body);
    }

    /**
     * Antwort auf einen schon gespeicherten Tausch (dieselbe client_ref): aktueller Stand beider Geräte, nichts geschrieben.
     */
    private function replayed(DeviceRepository $devices, Tenant $tenant, BookingResult $previous, int $inId): JsonResponse
    {
        [$out, $in] = $devices->swapPair($tenant, $previous->device->id, $inId);
        $swapId = '';
        if ($previous->eventId !== null) {
            $swapId = $devices->swapIdOfEvent($tenant, $previous->eventId) ?? '';
        }

        return new JsonResponse([
            'out' => DeviceJson::device($out),
            'in' => DeviceJson::device($in),
            'swap_id' => $swapId === '' ? null : $swapId,
            'result' => DeviceBookingJson::result($previous),
        ]);
    }

    /**
     * @return array{int, int} ID und Version
     *
     * @throws HttpException 400 invalid_request
     */
    private static function entry(RequestContext $context, string $field): array
    {
        $entry = $context->body[$field] ?? null;
        if (!is_array($entry) || array_is_list($entry)) {
            throw HttpException::invalidRequest(sprintf('Das Feld "%s" muss ein Objekt mit "id" und "version" sein.', $field));
        }
        /** @var array<string, mixed> $entry */
        $sub = new RequestContext(
            $context->request,
            $context->tenant,
            $context->db,
            $context->errors,
            $context->params,
            $entry,
            $context->auth,
            $context->hasher,
            $context->mailer,
            $context->urls,
            $context->permissions,
        );
        DeviceFields::rejectUnknown($sub, ['id', 'version']);

        return [DeviceFields::deviceId($sub), DeviceFields::version($sub)];
    }
}
