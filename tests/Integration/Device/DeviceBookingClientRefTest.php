<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceBookingJson;
use FirefightingEquipment\Api\V1\Devices\DeviceStateHandler;
use FirefightingEquipment\Device\Booking\BookingRequest;
use FirefightingEquipment\Device\Booking\ClientRef;
use FirefightingEquipment\Device\Booking\DeviceBookingRepository;
use FirefightingEquipment\Device\Booking\DuplicateClientRefException;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * POST devices/{id}/state mit client_ref: Quittung, Wiederholung ohne zweite Wirkung, Konflikte,
 * Mandantentrennung, Ausgabe in der Historie.
 */
#[CoversClass(DeviceStateHandler::class)]
#[CoversClass(DeviceBookingJson::class)]
#[CoversClass(DeviceBookingRepository::class)]
#[CoversClass(DeviceRepository::class)]
final class DeviceBookingClientRefTest extends DeviceApiTestCase
{
    private const string REF = '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f';
    private const string OTHER_REF = '9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d';

    public function testResultWithoutClientRef(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $json = $this->book($id, ['version' => 1, 'location_id' => $this->room]);

        self::assertSame([
            'outcome' => 'applied',
            'outcome_label' => 'übernommen',
            'reason' => null,
            'reason_label' => null,
            'message' => 'Übernommen. „MA-01“ ist jetzt: Lager, einsatzbereit.',
            'stale_confirmed' => false,
            'client_ref' => null,
            'replayed' => false,
            'notice_id' => null,
        ], $json['result']);
        self::assertSame(2, $json['device']['version']);
        self::assertSame(0, $this->countRows('fe_device_bookings'));
    }

    public function testSameBookingTwiceHasOneEffect(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $body = ['version' => 1, 'location_id' => $this->room, 'status' => 'defective', 'comment' => 'Riss', 'client_ref' => self::REF];

        $first = $this->book($id, $body, $this->user);
        $eventsAfterFirst = $this->events($id);
        $second = $this->book($id, $body, $this->user);
        $third = $this->book($id, [...$body, 'client_ref' => strtoupper(self::REF)], $this->user);

        self::assertSame(['applied', self::REF, false], [$first['result']['outcome'], $first['result']['client_ref'], $first['result']['replayed']]);
        self::assertSame(['applied', self::REF, true], [$second['result']['outcome'], $second['result']['client_ref'], $second['result']['replayed']]);
        self::assertTrue($third['result']['replayed']);
        self::assertSame(2, $second['device']['version']);
        self::assertSame($eventsAfterFirst, $this->events($id));

        $receipt = $this->pdo()->query('SELECT client_ref, user_id, device_id, outcome, reason, stale_confirmed, event_id FROM fe_device_bookings')->fetchAll();
        self::assertSame([[
            'client_ref' => self::REF, 'user_id' => $this->userId, 'device_id' => $id, 'outcome' => 'applied', 'reason' => null,
            'stale_confirmed' => 0, 'event_id' => end($eventsAfterFirst)['id'],
        ]], $receipt);
    }

    public function testReplayShowsCurrentStateAfterLaterChanges(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $body = ['version' => 1, 'location_id' => $this->room, 'client_ref' => self::REF];
        $this->book($id, $body, $this->user);
        $this->book($id, ['version' => 2, 'status' => 'maintenance']);

        $replay = $this->book($id, $body, $this->user);

        self::assertTrue($replay['result']['replayed']);
        self::assertSame('applied', $replay['result']['outcome']);
        self::assertSame(['maintenance', 3], [$replay['device']['status'], $replay['device']['version']]);
    }

    public function testUnchangedBookingIsStoredAndReplayed(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $body = ['version' => 1, 'location_id' => $this->g1, 'status' => 'ready', 'client_ref' => self::REF];

        $first = $this->book($id, $body);
        $replay = $this->book($id, $body);

        self::assertSame('unchanged', $first['result']['outcome']);
        self::assertSame('Keine Änderung: Aktueller Stand von „MA-01“: HLF 20 / G1, einsatzbereit.', $first['result']['message']);
        self::assertSame(['unchanged', true], [$replay['result']['outcome'], $replay['result']['replayed']]);
        self::assertSame(1, $replay['device']['version']);
        self::assertSame(['unchanged', null], array_values($this->pdo()->query('SELECT outcome, event_id FROM fe_device_bookings')->fetch()));
    }

    public function testSameClientRefForAnotherBookingIsConflict(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $other = $this->createViaApi('MA-02')['id'];
        $body = ['version' => 1, 'location_id' => $this->room, 'client_ref' => self::REF];
        $this->book($id, $body, $this->user);
        $before = $this->deviceSnapshot();

        self::assertError(409, 'client_ref_conflict', $this->api('POST', "devices/{$id}/state", [...$body, 'location_id' => $this->g1], $this->user));
        self::assertError(409, 'client_ref_conflict', $this->api('POST', "devices/{$id}/state", [...$body, 'comment' => 'anders'], $this->user));
        self::assertError(409, 'client_ref_conflict', $this->api('POST', "devices/{$id}/state", $body, $this->admin));
        self::assertError(409, 'client_ref_conflict', $this->api('POST', "devices/{$other}/state", $body, $this->user));

        self::assertSame($before, $this->deviceSnapshot());
        self::assertSame(1, $this->countRows('fe_device_bookings'));
    }

    public function testRejectedBookingStoresNothingAndCanBeRetried(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->book($id, ['version' => 1, 'status' => 'defective']);

        self::assertError(409, 'version_conflict', $this->api('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->room, 'client_ref' => self::REF]));
        self::assertSame(0, $this->countRows('fe_device_bookings'));

        $json = $this->book($id, ['version' => 2, 'location_id' => $this->room, 'client_ref' => self::REF]);
        self::assertSame(['applied', false], [$json['result']['outcome'], $json['result']['replayed']]);
    }

    public function testClientRefIsUniquePerTenant(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->account('tuttlingen', 'user@example.org');
        $otherCookie = $this->loginCookie('tuttlingen', 'user@example.org');
        $otherRoom = $this->createLocation($this->otherTenantId, ['name' => 'Lager', 'kind' => 'room']);
        $otherId = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);

        $this->book($id, ['version' => 1, 'location_id' => $this->room, 'client_ref' => self::REF]);
        $response = $this->api('POST', "devices/{$otherId}/state", ['version' => 1, 'location_id' => $otherRoom, 'client_ref' => self::REF], $otherCookie, 'tuttlingen');

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['applied', false], [self::json($response)['result']['outcome'], self::json($response)['result']['replayed']]);
        self::assertSame(2, $this->countRows('fe_device_bookings'));

        // Fremdes Gerät mit der client_ref einer anderen Stadt: 404 wie ohne.
        self::assertError(404, 'not_found', $this->api('POST', "devices/{$otherId}/state", ['version' => 2, 'status' => 'defective', 'client_ref' => self::OTHER_REF]));
    }

    public function testInvalidFields(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $invalid = [
            ['client_ref' => 'abc'],
            ['client_ref' => '3f2c8a1e-5b7d-1c9e-8f10-2a3b4c5d6e7f'],
            ['client_ref' => self::REF . "\n"],
            ['client_ref' => 42],
            ['occurred_at' => '2026-06-15T08:00:00Z'],
            ['client_ref' => self::REF, 'occurred_at' => '15.06.2026 08:00'],
            ['client_ref' => self::REF, 'confirmed_stale' => true],
            ['client_ref' => self::REF, 'occurred_at' => '2026-06-15T08:00:00Z', 'confirmed_stale' => 1],
        ];
        foreach ($invalid as $fields) {
            $response = $this->api('POST', "devices/{$id}/state", ['version' => 1, 'status' => 'defective', ...$fields]);
            self::assertError(400, 'invalid_request', $response);
        }
        self::assertError(400, 'invalid_request', $this->api('POST', "devices/{$id}/fields", ['version' => 1, 'note' => 'x', 'client_ref' => self::REF]));
        self::assertError(400, 'invalid_request', $this->api('POST', "devices/{$id}/retire", ['version' => 1, 'occurred_at' => '2026-06-15T08:00:00Z']));
        self::assertSame(1, self::json($this->api('GET', "devices/{$id}"))['device']['version']);
    }

    public function testClientRefAppearsInHistory(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->book($id, ['version' => 1, 'location_id' => $this->room, 'client_ref' => self::REF]);

        $events = self::json($this->api('GET', "devices/{$id}/events"))['events'];

        self::assertSame([self::REF, null], array_column($events, 'client_ref'));
    }

    public function testRepositoryRollsBackWhenClientRefWasStoredConcurrently(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $first = $this->createViaApi('MA-02')['id'];
        $this->book($first, ['version' => 1, 'status' => 'defective', 'client_ref' => self::REF]);
        $before = $this->deviceSnapshot();
        $change = new DeviceStateChange($this->room, null);

        try {
            new DeviceRepository($this->pdo())->book(
                $this->tenant('immendingen'),
                $this->adminId,
                $id,
                1,
                $change,
                null,
                new BookingRequest(ClientRef::fromString(self::REF), null, false, BookingRequest::hash($id, 1, $change, null, null)),
            );
            self::fail('DuplicateClientRefException erwartet.');
        } catch (DuplicateClientRefException) {
        }

        self::assertSame($before, $this->deviceSnapshot());
        self::assertFalse($this->pdo()->inTransaction());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function book(int $id, array $body, ?string $cookie = null): array
    {
        $response = $this->api('POST', "devices/{$id}/state", $body, $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }
}
