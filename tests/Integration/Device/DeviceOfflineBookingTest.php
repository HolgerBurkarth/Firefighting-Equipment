<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceBookingJson;
use FirefightingEquipment\Api\V1\Devices\DeviceStateHandler;
use FirefightingEquipment\Config\OfflineConfig;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\ClientRef;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Offline\ActionTime;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Offline-Buchungen (POST devices/{id}/state mit occurred_at): „der Letzte gewinnt“ nach dem
 * gemeinsamen Stand, nicht übernommene Buchungen nur in der Historie, Grenzen der Uhrzeit, alte
 * Buchungen nach Rückfrage, Wiederholung, gleichzeitige Buchungen.
 *
 * Die feste Uhr steht auf der Zeit der Datenbank (Sekunden), damit state_changed_at (vom Server
 * geschrieben) und Aktionszeiten vergleichbar sind; Zeitpunkte relativ dazu.
 */
#[CoversClass(DeviceStateHandler::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceBookingJson::class)]
final class DeviceOfflineBookingTest extends DeviceApiTestCase
{
    use OfflineBookings;

    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOffline();
    }

    public function testCurrentVersionIsBookedWithActionTime(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-2 hours')]);

        self::assertSame(['applied', null, false], [$json['result']['outcome'], $json['result']['reason'], $json['result']['stale_confirmed']]);
        self::assertSame([$this->room, 2], [$json['device']['location']['id'], $json['device']['version']]);
        self::assertSame($this->sql('-2 hours'), $this->stateChangedAt($id));
        $event = $this->lastEvent($id);
        self::assertSame([$this->sql('-2 hours'), 1, null], [$event['occurred_at'], $event['applied'], $event['not_applied_reason']]);
    }

    public function testNewerActionOverwritesOtherState(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->book($id, ['version' => 1, 'status' => 'defective'], $this->admin);
        $this->setStateChangedAt($id, '-3 hours');

        $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'status' => 'maintenance', 'occurred_at' => $this->at('-1 hour')]);

        self::assertSame(['applied', 'overwritten'], [$json['result']['outcome'], $json['result']['reason']]);
        self::assertStringStartsWith('Übernommen. Ein zwischenzeitlich gebuchter, älterer Stand wurde überschrieben', $json['result']['message']);
        self::assertSame([$this->room, 'maintenance', 3], [$json['device']['location']['id'], $json['device']['status'], $json['device']['version']]);
        self::assertSame($this->sql('-1 hour'), $this->stateChangedAt($id));
        $event = $this->lastEvent($id);
        self::assertSame([$this->g1, $this->room, 'defective', 'maintenance', 1], [
            $event['from_location_id'], $event['to_location_id'], $event['from_status'], $event['to_status'], $event['applied'],
        ]);
    }

    public function testOlderOrEqualActionIsHistoryOnly(): void
    {
        foreach (['-1 hour' => '-2 hours', 'gleich' => '-1 hour'] as $case => $action) {
            $id = $this->createViaApi('MA-' . $case)['id'];
            $this->book($id, ['version' => 1, 'status' => 'defective'], $this->admin);
            $this->setStateChangedAt($id, '-1 hour');
            $before = $this->deviceSnapshotOf($id);

            $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'comment' => 'Wache 2', 'occurred_at' => $this->at($action)]);

            self::assertSame(['history_only', 'older'], [$json['result']['outcome'], $json['result']['reason']], $case);
            self::assertStringStartsWith('Nur in der Historie: Das Gerät wurde nach Ihrer Buchung bereits anders gebucht.', $json['result']['message']);
            self::assertSame($before, $this->deviceSnapshotOf($id), $case);
            $event = $this->lastEvent($id);
            self::assertSame(
                ['state_changed', null, $this->room, null, null, 0, 'older', $this->sql($action), 'Wache 2'],
                [$event['event_type'], $event['from_location_id'], $event['to_location_id'], $event['from_status'], $event['to_status'],
                    $event['applied'], $event['not_applied_reason'], $event['occurred_at'], $event['comment']],
                $case,
            );
        }
    }

    public function testStaleVersionWithoutStateChangeIsBookedNormally(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "devices/{$id}/fields", ['version' => 1, 'note' => 'Neu']);
        $this->api('POST', "devices/{$id}/inspection-due", ['version' => 2, 'next_inspection_due' => '2027-01-01']);
        $this->setStateChangedAt($id, '-1 hour');

        // Aktionszeit sogar älter als der Stand: Der Stand hat sich seit Version 1 nicht geändert.
        $json = $this->offline($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-2 hours')]);

        self::assertSame(['applied', null], [$json['result']['outcome'], $json['result']['reason']]);
        self::assertSame(['defective', 4], [$json['device']['status'], $json['device']['version']]);
        self::assertSame(4, $this->stateRow($id)['state_version']);
    }

    public function testWithoutActionTimeStaleVersionIsStillConflict(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->book($id, ['version' => 1, 'status' => 'defective'], $this->admin);

        self::assertError(409, 'version_conflict', $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->room, 'client_ref' => $this->ref()], $this->user));
    }

    public function testRetiredDeviceIsNotApplied(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "devices/{$id}/retire", ['version' => 1]);
        $before = $this->deviceSnapshotOf($id);

        $json = $this->offline($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]);

        self::assertSame(['not_applied', 'retired'], [$json['result']['outcome'], $json['result']['reason']]);
        self::assertStringStartsWith('Nicht übernommen: Das Gerät wurde inzwischen ausgesondert.', $json['result']['message']);
        self::assertSame($before, $this->deviceSnapshotOf($id));
        $event = $this->lastEvent($id);
        self::assertSame([null, 'defective', 0, 'retired'], [$event['to_location_id'], $event['to_status'], $event['applied'], $event['not_applied_reason']]);
    }

    public function testLockedTargetIsNotApplied(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "locations/{$this->room}/lock", []);

        $json = $this->offline($id, ['version' => 1, 'location_id' => $this->room, 'status' => 'maintenance', 'occurred_at' => $this->at('-1 minute')]);

        self::assertSame(['not_applied', 'location_locked'], [$json['result']['outcome'], $json['result']['reason']]);
        self::assertSame([$this->g1, 'ready', 1], [$json['device']['location']['id'], $json['device']['status'], $json['device']['version']]);
        $event = $this->lastEvent($id);
        self::assertSame([$this->room, 'maintenance', 0, 'location_locked'], [$event['to_location_id'], $event['to_status'], $event['applied'], $event['not_applied_reason']]);

        // Online bleibt es ein Fehler.
        self::assertError(409, 'rule_violation', $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->room], $this->user));
    }

    public function testUnknownOrForeignTargetIsStillAnError(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        self::assertError(400, 'invalid_request', $this->offlineResponse($id, ['version' => 1, 'location_id' => 999999999, 'occurred_at' => $this->at('-1 minute')]));
        self::assertError(400, 'invalid_request', $this->offlineResponse($id, ['version' => 1, 'location_id' => $this->otherLocation, 'occurred_at' => $this->at('-1 minute')]));
        self::assertSame(0, $this->countRows('fe_device_bookings'));
    }

    public function testOtherTenantsDeviceIsNotFound(): void
    {
        $otherId = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);

        self::assertError(404, 'not_found', $this->offlineResponse($otherId, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]));
    }

    public function testUnchangedOfflineBooking(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->book($id, ['version' => 1, 'status' => 'defective'], $this->admin);
        $this->setStateChangedAt($id, '-3 hours');

        $json = $this->offline($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 hour')]);

        self::assertSame(['unchanged', null], [$json['result']['outcome'], $json['result']['reason']]);
        self::assertSame($this->sql('-3 hours'), $this->stateChangedAt($id));
    }

    public function testClockSkewLimit(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $response = $this->offlineResponse($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('+301 seconds')]);
        self::assertError(422, 'clock_implausible', $response);
        self::assertSame([
            'server_time' => $this->at('+0 seconds'),
            'occurred_at' => $this->at('+301 seconds'),
            'max_skew_seconds' => 300,
        ], self::json($response)['details']);
        self::assertSame(0, $this->countRows('fe_device_bookings'));

        // Bruchteile zählen nicht: auf Sekunden gekürzt.
        $atLimit = $this->now->modify('+300 seconds')->format('Y-m-d\TH:i:s') . '.999Z';
        self::assertSame('applied', $this->offline($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $atLimit])['result']['outcome']);
    }

    public function testStaleLimitAndConfirmation(): void
    {
        $this->useOfflineConfig(new OfflineConfig(queueMaxAgeDays: 3));
        $id = $this->createViaApi('MA-01')['id'];
        $this->setStateChangedAt($id, '-10 days');

        $exactly = $this->offline($id, ['version' => 1, 'status' => 'maintenance', 'occurred_at' => $this->at('-3 days')]);
        self::assertSame(['applied', false], [$exactly['result']['outcome'], $exactly['result']['stale_confirmed']]);

        $body = ['version' => 2, 'status' => 'defective', 'occurred_at' => $this->at('-3 days -1 second')];
        $response = $this->offlineResponse($id, $body);
        self::assertError(409, 'stale_action', $response);
        self::assertSame(['occurred_at' => $this->at('-3 days -1 second'), 'queue_max_age_days' => 3, 'server_time' => $this->at('+0 seconds')], self::json($response)['details']);
        self::assertSame(1, $this->countRows('fe_device_bookings'));

        $confirmed = $this->offline($id, [...$body, 'client_ref' => $this->lastRef(), 'confirmed_stale' => true]);
        self::assertSame(['applied', null, true], [$confirmed['result']['outcome'], $confirmed['result']['reason'], $confirmed['result']['stale_confirmed']]);
        self::assertStringEndsWith('Die Buchung war älter als erlaubt und wurde nach Rückfrage gesendet; die Admins wurden informiert.', $confirmed['result']['message']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT stale_confirmed FROM fe_device_bookings ORDER BY id DESC LIMIT 1')->fetchColumn());

        // confirmed_stale bei einer nicht alten Buchung ändert nichts.
        $fresh = $this->offline($id, ['version' => 3, 'status' => 'ready', 'occurred_at' => $this->at('-1 minute'), 'confirmed_stale' => true]);
        self::assertFalse($fresh['result']['stale_confirmed']);
    }

    public function testReplayIsAnsweredEvenAfterTheLimits(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $body = ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute'), 'client_ref' => $this->ref()];
        $this->offline($id, $body);

        $this->clock->set($this->now->modify('+30 days')->format('Y-m-d\TH:i:s\Z'));
        $replay = $this->offline($id, $body);
        self::assertSame(['applied', true], [$replay['result']['outcome'], $replay['result']['replayed']]);

        $this->clock->set($this->now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'));
        self::assertTrue($this->offline($id, $body)['result']['replayed']);
        self::assertCount(2, $this->events($id));
    }

    public function testTwoPhonesLastActionWins(): void
    {
        // Beide Handys kennen Version 1; das ältere kommt zuerst an, dann das jüngere.
        $first = $this->createViaApi('MA-01')['id'];
        $this->setStateChangedAt($first, '-1 day');
        $a = $this->offline($first, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-2 hours')], $this->user);
        $b = $this->offline($first, ['version' => 1, 'location_id' => $this->hlf, 'occurred_at' => $this->at('-1 hour')], $this->admin);
        self::assertSame([['applied', null], ['applied', 'overwritten']], [[$a['result']['outcome'], $a['result']['reason']], [$b['result']['outcome'], $b['result']['reason']]]);
        self::assertSame($this->hlf, $b['device']['location']['id']);

        // Umgekehrte Reihenfolge des Eingangs: dasselbe Endergebnis.
        $second = $this->createViaApi('MA-02')['id'];
        $this->setStateChangedAt($second, '-1 day');
        $b = $this->offline($second, ['version' => 1, 'location_id' => $this->hlf, 'occurred_at' => $this->at('-1 hour')], $this->admin);
        $a = $this->offline($second, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-2 hours')], $this->user);
        self::assertSame([['applied', null], ['history_only', 'older']], [[$b['result']['outcome'], $b['result']['reason']], [$a['result']['outcome'], $a['result']['reason']]]);
        self::assertSame($this->hlf, $a['device']['location']['id']);
    }

    public function testOfflineBookingWaitsForConcurrentChangeOfTheDevice(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $before = $this->deviceSnapshot();
        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->prepare('SELECT id FROM fe_devices WHERE id = ? FOR UPDATE')->execute([$id]);
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $change = new DeviceStateChange($this->room, null);
        $at = ActionTime::fromString($this->at('-1 minute'));
        $ref = ClientRef::fromString($this->ref());
        try {
            new DeviceRepository($this->pdo())->book($this->tenant('immendingen'), $this->userId, $id, 1, $change, null, new BookingRequest($ref, $at, false, BookingRequest::hash($id, 1, $change, null, $at)));
            self::fail('Die Buchung hat nicht auf die Sperre der zweiten Verbindung gewartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
        } finally {
            $concurrent->rollBack();
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertSame($before, $this->deviceSnapshot());
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testOfflineBookingWaitsWhileTargetIsBeingLocked(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $before = $this->deviceSnapshot();
        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->prepare('SELECT id FROM fe_locations WHERE id = ? FOR UPDATE')->execute([$this->room]);
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $change = new DeviceStateChange($this->room, null);
        $at = ActionTime::fromString($this->at('-1 minute'));
        try {
            new DeviceRepository($this->pdo())->book($this->tenant('immendingen'), $this->userId, $id, 1, $change, null, new BookingRequest(ClientRef::fromString($this->ref()), $at, false, BookingRequest::hash($id, 1, $change, null, $at)));
            self::fail('Die Buchung hat nicht auf die Sperre des Standorts gewartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
        } finally {
            $concurrent->rollBack();
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertSame($before, $this->deviceSnapshot());
    }

    /**
     * Online-Buchung ohne Aktionszeit (Serverzeit).
     *
     * @param array<string, mixed> $body
     */
    private function book(int $id, array $body, string $cookie): void
    {
        $response = $this->api('POST', "devices/{$id}/state", $body, $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
    }

    private function stateChangedAt(int $id): string
    {
        return (string) $this->stateRow($id)['state_changed_at'];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateRow(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT state_changed_at, state_version, version FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: self::fail('Gerät fehlt.');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(int $id): array
    {
        $events = $this->events($id);

        return end($events) ?: self::fail('Keine Ereignisse.');
    }

    private function deviceSnapshotOf(int $id): string
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return json_encode($stmt->fetch(), JSON_THROW_ON_ERROR);
    }
}
