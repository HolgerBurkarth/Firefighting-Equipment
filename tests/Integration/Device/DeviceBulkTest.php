<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\Booking\DeviceBooker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Sammelaktionen (Admin): POST devices/bulk/inspection-due und POST devices/bulk/state. Jedes Gerät
 * einzeln in eigener Transaktion mit eigenem Eintrag im Verlauf, Ergebnis je Gerät, Teilerfolg;
 * Fehler der ganzen Anfrage (400) ändern nichts.
 */
#[CoversClass(DeviceBulkHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceBooker::class)]
final class DeviceBulkTest extends DeviceApiTestCase
{
    private const string REF = '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f';
    private const string OTHER_REF = '9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d';

    public function testInspectionDueForSeveralDevices(): void
    {
        $first = $this->createViaApi('MA-01');
        $second = $this->createViaApi('MA-02', ['next_inspection_due' => '2027-01-15']);
        $same = $this->createViaApi('MA-03', ['next_inspection_due' => '2027-03-01']);

        $json = $this->bulk('inspection-due', [
            ['id' => $first['id'], 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => $second['id'], 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => $same['id'], 'version' => 1, 'next_inspection_due' => '2027-03-01'],
        ], 'Prüfung beauftragt');

        self::assertSame(['total' => 3, 'applied' => 2, 'unchanged' => 1, 'errors' => 0], $json['summary']);
        self::assertSame(
            [[$first['id'], 'applied', 2, '2027-03-01'], [$second['id'], 'applied', 2, '2027-03-01'], [$same['id'], 'unchanged', 1, '2027-03-01']],
            array_map(static fn (array $r): array => [$r['id'], $r['outcome'], $r['device']['version'], $r['device']['next_inspection_due']], $json['results']),
        );
        self::assertSame([false, null], [$json['results'][0]['replayed'], $json['results'][0]['error']]);
        self::assertSame(
            [['due_changed', null, '2027-03-01', 'Prüfung beauftragt']],
            array_map(static fn (array $e): array => [$e['event_type'], $e['from_due'], $e['to_due'], $e['comment']], array_slice($this->events($first['id']), 1)),
        );
        self::assertSame(
            [['due_changed', '2027-01-15', '2027-03-01', 'Prüfung beauftragt']],
            array_map(static fn (array $e): array => [$e['event_type'], $e['from_due'], $e['to_due'], $e['comment']], array_slice($this->events($second['id']), 1)),
            'eigener Eintrag je Gerät',
        );
        self::assertCount(1, $this->events($same['id']), 'ohne Änderung kein Eintrag');
    }

    public function testRemoveDueDateWithNull(): void
    {
        $device = $this->createViaApi('MA-01', ['next_inspection_due' => '2027-01-15']);

        $json = $this->bulk('inspection-due', [['id' => $device['id'], 'version' => 1, 'next_inspection_due' => null]]);

        self::assertSame([null, 2], [$json['results'][0]['device']['next_inspection_due'], $json['results'][0]['device']['version']]);
    }

    public function testPartialSuccessWithConflictRetiredAndUnknownDevices(): void
    {
        $ok = $this->createViaApi('MA-01');
        $stale = $this->createViaApi('MA-02');
        $retired = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-09', 'status' => 'retired']);
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'T-1']);
        $this->api('POST', 'devices/' . $stale['id'] . '/fields', ['version' => 1, 'model' => 'FPS 7000']);

        $json = $this->bulk('inspection-due', [
            ['id' => $ok['id'], 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => $stale['id'], 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => $retired, 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => $foreign, 'version' => 1, 'next_inspection_due' => '2027-03-01'],
            ['id' => 999999, 'version' => 1, 'next_inspection_due' => '2027-03-01'],
        ]);

        self::assertSame(['total' => 5, 'applied' => 1, 'unchanged' => 0, 'errors' => 4], $json['summary']);
        self::assertSame(
            ['applied', 'version_conflict', 'rule_violation', 'not_found', 'not_found'],
            array_map(static fn (array $r): string => $r['error']['error'] ?? $r['outcome'], $json['results']),
        );
        $conflict = $json['results'][1];
        self::assertSame(['error', 2, 'FPS 7000'], [$conflict['outcome'], $conflict['device']['version'], $conflict['device']['model']], 'aktueller Stand');
        self::assertSame(2, $conflict['error']['details']['device']['version']);
        self::assertSame('retired', $json['results'][2]['device']['status']);
        self::assertNull($json['results'][3]['device'], 'fremdes Gerät wird nicht gezeigt');
        self::assertArrayNotHasKey('details', $json['results'][3]['error']);
        self::assertNull($this->deviceRow($stale['id'])['next_inspection_due']);
        self::assertSame('2027-03-01', $this->deviceRow($ok['id'])['next_inspection_due']);
    }

    public function testStateForSeveralDevicesWithOwnEvents(): void
    {
        $first = $this->createViaApi('MA-01');
        $second = $this->createViaApi('MA-02', ['location_id' => $this->hlf, 'status' => 'maintenance']);

        $json = $this->bulk('state', [
            ['id' => $first['id'], 'version' => 1, 'location_id' => $this->room, 'status' => 'defective'],
            ['id' => $second['id'], 'version' => 1, 'location_id' => $this->room, 'status' => 'defective'],
        ], 'Nach Einsatz');

        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $json['summary']);
        self::assertSame(['Lager', 'defective', 2], [$json['results'][0]['device']['location']['path'], $json['results'][0]['device']['status'], $json['results'][0]['device']['version']]);
        self::assertSame(
            ['state_changed', $this->adminId, $this->g1, $this->room, 'ready', 'defective', 'Nach Einsatz'],
            self::state(array_slice($this->events($first['id']), 1)[0]),
        );
        self::assertSame(
            ['state_changed', $this->adminId, $this->hlf, $this->room, 'maintenance', 'defective', 'Nach Einsatz'],
            self::state(array_slice($this->events($second['id']), 1)[0]),
        );
    }

    public function testStateOnlyChangesWhatDiffers(): void
    {
        $here = $this->createViaApi('MA-01', ['location_id' => $this->room]);
        $elsewhere = $this->createViaApi('MA-02');

        $json = $this->bulk('state', [
            ['id' => $here['id'], 'version' => 1, 'location_id' => $this->room],
            ['id' => $elsewhere['id'], 'version' => 1, 'location_id' => $this->room],
        ]);

        self::assertSame(['unchanged', 'applied'], array_column($json['results'], 'outcome'));
        self::assertCount(1, $this->events($here['id']));
        self::assertSame(['state_changed', $this->adminId, $this->g1, $this->room, null, null, null], self::state($this->events($elsewhere['id'])[1]));
    }

    public function testStateErrorsPerDevice(): void
    {
        $ok = $this->createViaApi('MA-01');
        $retired = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-09', 'status' => 'retired']);
        $locked = $this->createLocation($this->tenantId, ['name' => 'Altes Lager', 'kind' => 'room', 'active' => 0]);
        $toLocked = $this->createViaApi('MA-02');
        $toUnknown = $this->createViaApi('MA-03');

        $json = $this->bulk('state', [
            ['id' => $ok['id'], 'version' => 1, 'status' => 'maintenance'],
            ['id' => $retired, 'version' => 1, 'status' => 'maintenance'],
            ['id' => $toLocked['id'], 'version' => 1, 'location_id' => $locked],
            ['id' => $toUnknown['id'], 'version' => 1, 'location_id' => $this->otherLocation],
        ]);

        self::assertSame(
            ['applied', 'rule_violation', 'rule_violation', 'invalid_request'],
            array_map(static fn (array $r): string => $r['error']['error'] ?? $r['outcome'], $json['results']),
        );
        self::assertSame([1, 1], [$json['results'][2]['device']['version'], $json['results'][3]['device']['version']]);
        self::assertCount(1, $this->events($toLocked['id']));
    }

    public function testClientRefPreventsDoubleEffectAndDetectsConflicts(): void
    {
        $first = $this->createViaApi('MA-01');
        $second = $this->createViaApi('MA-02');
        $entries = [
            ['id' => $first['id'], 'version' => 1, 'status' => 'defective', 'client_ref' => self::REF],
            ['id' => $second['id'], 'version' => 1, 'status' => 'defective', 'client_ref' => self::OTHER_REF],
        ];

        $sent = $this->bulk('state', $entries, 'Riss');
        $eventsAfter = [$this->events($first['id']), $this->events($second['id'])];
        $again = $this->bulk('state', $entries, 'Riss');
        $otherComment = $this->bulk('state', [$entries[0]], 'Anderer Text');

        self::assertSame([false, false], array_column($sent['results'], 'replayed'));
        self::assertSame([true, true], array_column($again['results'], 'replayed'));
        self::assertSame(['applied', 'applied'], array_column($again['results'], 'outcome'));
        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $again['summary']);
        self::assertSame($eventsAfter, [$this->events($first['id']), $this->events($second['id'])], 'keine zweite Wirkung');
        self::assertSame('client_ref_conflict', $otherComment['results'][0]['error']['error']);
        self::assertSame(2, $this->countRows('fe_device_bookings'));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidRequests')]
    public function testInvalidRequestChangesNothing(string $action, array $body, string $message): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $other = $this->createViaApi('MA-02')['id'];
        $body = json_decode(strtr(json_encode($body, JSON_THROW_ON_ERROR), ['"{id}"' => (string) $id, '"{other}"' => (string) $other]), true, flags: JSON_THROW_ON_ERROR);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/bulk/' . $action, $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidRequests(): iterable
    {
        $entry = ['id' => '{id}', 'version' => 1, 'next_inspection_due' => '2027-03-01'];
        $tooMany = array_map(static fn (int $i): array => ['id' => $i, 'version' => 1, 'next_inspection_due' => '2027-03-01'], range(1, DeviceBulkHandler::MAX_DEVICES + 1));

        yield 'devices fehlt' => ['inspection-due', [], '"devices" muss eine Liste mit 1 bis 50'];
        yield 'leere Liste' => ['inspection-due', ['devices' => []], 'Liste mit 1 bis 50'];
        yield 'zu viele' => ['inspection-due', ['devices' => $tooMany], 'Liste mit 1 bis 50'];
        yield 'Objekt statt Liste' => ['inspection-due', ['devices' => ['a' => $entry]], 'Liste mit 1 bis 50'];
        yield 'Eintrag kein Objekt' => ['inspection-due', ['devices' => [5]], 'Eintrag 1 in "devices" muss ein Objekt'];
        yield 'unbekanntes Feld oben' => ['inspection-due', ['devices' => [$entry], 'status' => 'ready'], 'Das Feld "status" ist hier nicht vorgesehen'];
        yield 'unbekanntes Feld im Eintrag' => ['inspection-due', ['devices' => [[...$entry, 'status' => 'ready']]], 'Eintrag 1 in "devices": Das Feld "status"'];
        yield 'Datum fehlt' => ['inspection-due', ['devices' => [['id' => '{id}', 'version' => 1]]], 'Eintrag 1 in "devices": Das Feld "next_inspection_due" fehlt'];
        yield 'Datum im zweiten Eintrag ungültig' => ['inspection-due', ['devices' => [$entry, ['id' => '{other}', 'version' => 1, 'next_inspection_due' => '01.03.2027']]], 'Eintrag 2 in "devices": '];
        yield 'ID fehlt' => ['inspection-due', ['devices' => [['version' => 1, 'next_inspection_due' => '2027-03-01']]], 'Eintrag 1 in "devices": Das Feld "id"'];
        yield 'Version fehlt' => ['inspection-due', ['devices' => [['id' => '{id}', 'next_inspection_due' => '2027-03-01']]], 'Das Feld "version"'];
        yield 'Gerät doppelt' => ['inspection-due', ['devices' => [$entry, $entry]], 'steht mehrfach in "devices"'];
        yield 'Kommentar mehrzeilig' => ['inspection-due', ['devices' => [$entry], 'comment' => "a\nb"], 'Kommentar'];
        yield 'Umbuchen ohne Ziel' => ['state', ['devices' => [['id' => '{id}', 'version' => 1]]], 'Eintrag 1 in "devices": Bitte einen neuen Standort'];
        yield 'Umbuchen auf ausgesondert' => ['state', ['devices' => [['id' => '{id}', 'version' => 1, 'status' => 'retired']]], 'Aussondern über'];
        yield 'Aktionszeit nicht vorgesehen' => ['state', ['devices' => [['id' => '{id}', 'version' => 1, 'status' => 'ready', 'occurred_at' => '2026-06-15T10:00:00Z']]], 'Das Feld "occurred_at"'];
        yield 'client_ref ungültig' => ['state', ['devices' => [['id' => '{id}', 'version' => 1, 'status' => 'ready', 'client_ref' => 'abc']]], 'UUID'];
    }

    public function testOnlyForAdmins(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        self::assertError(403, 'forbidden', $this->api('POST', 'devices/bulk/state', ['devices' => [['id' => $id, 'version' => 1, 'status' => 'defective']]], $this->user));
    }

    /**
     * @param list<array<string, mixed>> $devices
     *
     * @return array<string, mixed>
     */
    private function bulk(string $action, array $devices, ?string $comment = null): array
    {
        $response = $this->api('POST', 'devices/bulk/' . $action, ['devices' => $devices, ...($comment === null ? [] : ['comment' => $comment])]);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceRow(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch();
    }
}
