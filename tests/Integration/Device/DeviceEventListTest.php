<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceEventJson;
use FirefightingEquipment\Api\V1\Devices\DeviceEventListHandler;
use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * GET devices/{id}/events (angemeldet): Historie neueste zuerst, seitenweise, mit deutschen
 * Bezeichnungen, Anzeigenamen (anonymisiert „Gelöschter Benutzer #ID“) und aktuellen Standortpfaden.
 */
#[CoversClass(DeviceEventListHandler::class)]
#[CoversClass(DeviceEventJson::class)]
#[CoversClass(DeviceEventRepository::class)]
#[CoversClass(DeviceEvent::class)]
#[CoversClass(DeviceEventLocation::class)]
final class DeviceEventListTest extends DeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Kommandant', $this->adminId]);
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Gerätewart', $this->userId]);
    }

    public function testCompleteHistoryNewestFirst(): void
    {
        $id = $this->createViaApi('MA-01', ['comment' => 'Neu beschafft'])['id'];
        $this->change('fields', ['version' => 1, 'serial_number' => 'S-1', 'procured_on' => '2024-05-01']);
        $this->change('inspection-due', ['version' => 2, 'next_inspection_due' => '2027-03-01']);
        $this->change('state', ['version' => 3, 'location_id' => $this->room, 'comment' => 'Zur Wartung'], $this->user);
        $this->change('state', ['version' => 4, 'status' => 'defective'], $this->user);
        $this->change('state', ['version' => 5, 'location_id' => $this->g1, 'status' => 'ready'], $this->user);
        $this->change('retire', ['version' => 6, 'comment' => 'Ausgemustert']);
        $this->change('reinstate', ['version' => 7, 'status' => 'maintenance', 'identifier' => 'MA-01a']);

        $response = $this->api('GET', 'devices/' . $id . '/events', cookie: $this->user);

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame([10, 50, 0], [$json['total'], $json['limit'], $json['offset']]);
        $events = $json['events'];
        self::assertSame(array_reverse(array_column($events, 'id')), array_column($this->events($id), 'id'));

        $g1 = ['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'];
        $room = ['id' => $this->room, 'name' => 'Lager', 'path' => 'Lager'];
        $kommandant = ['id' => $this->adminId, 'name' => 'Kommandant'];
        $gerätewart = ['id' => $this->userId, 'name' => 'Gerätewart'];
        $expected = [
            [
                'type' => 'state_changed', 'type_label' => 'Wieder aufgenommen', 'user' => $kommandant, 'comment' => null, 'location' => null,
                'status' => ['from' => 'retired', 'from_label' => 'ausgesondert', 'to' => 'maintenance', 'to_label' => 'in Wartung'],
                'inspection_due' => null, 'field' => null,
            ],
            [
                'type' => 'field_changed', 'type_label' => 'Stammdaten geändert', 'user' => $kommandant, 'comment' => null, 'location' => null,
                'status' => null, 'inspection_due' => null, 'field' => ['name' => 'identifier', 'label' => 'Kennung', 'old' => 'MA-01', 'new' => 'MA-01a'],
            ],
            [
                'type' => 'state_changed', 'type_label' => 'Ausgesondert', 'user' => $kommandant, 'comment' => 'Ausgemustert', 'location' => null,
                'status' => ['from' => 'ready', 'from_label' => 'einsatzbereit', 'to' => 'retired', 'to_label' => 'ausgesondert'],
                'inspection_due' => null, 'field' => null,
            ],
            [
                'type' => 'state_changed', 'type_label' => 'Umgebucht und Status geändert', 'user' => $gerätewart, 'comment' => null,
                'location' => ['from' => $room, 'to' => $g1],
                'status' => ['from' => 'defective', 'from_label' => 'defekt', 'to' => 'ready', 'to_label' => 'einsatzbereit'],
                'inspection_due' => null, 'field' => null,
            ],
            [
                'type' => 'state_changed', 'type_label' => 'Status geändert', 'user' => $gerätewart, 'comment' => null, 'location' => null,
                'status' => ['from' => 'ready', 'from_label' => 'einsatzbereit', 'to' => 'defective', 'to_label' => 'defekt'],
                'inspection_due' => null, 'field' => null,
            ],
            [
                'type' => 'state_changed', 'type_label' => 'Umgebucht', 'user' => $gerätewart, 'comment' => 'Zur Wartung',
                'location' => ['from' => $g1, 'to' => $room], 'status' => null, 'inspection_due' => null, 'field' => null,
            ],
            [
                'type' => 'due_changed', 'type_label' => 'Prüfdatum geändert', 'user' => $kommandant, 'comment' => null, 'location' => null,
                'status' => null, 'inspection_due' => ['from' => null, 'to' => '2027-03-01'], 'field' => null,
            ],
            [
                'type' => 'field_changed', 'type_label' => 'Stammdaten geändert', 'user' => $kommandant, 'comment' => null, 'location' => null,
                'status' => null, 'inspection_due' => null, 'field' => ['name' => 'procured_on', 'label' => 'Beschaffungsdatum', 'old' => null, 'new' => '2024-05-01'],
            ],
            [
                'type' => 'field_changed', 'type_label' => 'Stammdaten geändert', 'user' => $kommandant, 'comment' => null, 'location' => null,
                'status' => null, 'inspection_due' => null, 'field' => ['name' => 'serial_number', 'label' => 'Seriennummer', 'old' => null, 'new' => 'S-1'],
            ],
            [
                'type' => 'created', 'type_label' => 'Angelegt', 'user' => $kommandant, 'comment' => 'Neu beschafft',
                'location' => ['from' => null, 'to' => $g1],
                'status' => ['from' => null, 'from_label' => null, 'to' => 'ready', 'to_label' => 'einsatzbereit'],
                'inspection_due' => null, 'field' => null,
            ],
        ];
        self::assertCount(count($expected), $events);
        foreach ($expected as $i => $entry) {
            $actual = $events[$i];
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $actual['created_at']);
            // Serveraktionen: Aktionszeit = Eingang, übernommen, ohne Buchungs-Kennung.
            self::assertSame([$actual['created_at'], $actual['created_at'], true, null, null, null], [
                $actual['occurred_at'], $actual['received_at'], $actual['applied'],
                $actual['not_applied_reason'], $actual['not_applied_label'], $actual['client_ref'],
            ]);
            unset($actual['id'], $actual['created_at'], $actual['occurred_at'], $actual['received_at'], $actual['applied'],
                $actual['not_applied_reason'], $actual['not_applied_label'], $actual['client_ref']);
            self::assertSame($entry, $actual, sprintf('Eintrag %d', $i));
        }
    }

    public function testOfflineEntriesShowActionTimeAndNotApplied(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $this->insert('fe_device_events', [
            'tenant_id' => $this->tenantId, 'device_id' => $id, 'user_id' => $this->userId, 'event_type' => 'state_changed',
            'from_location_id' => $this->g1, 'to_location_id' => $this->room, 'occurred_at' => '2026-06-14 08:00:00',
            'created_at' => '2026-06-15 09:30:00',
        ]);
        $this->insert('fe_device_events', [
            'tenant_id' => $this->tenantId, 'device_id' => $id, 'user_id' => $this->userId, 'event_type' => 'state_changed',
            'to_location_id' => $this->g1, 'to_status' => 'defective', 'occurred_at' => '2026-06-13 07:00:00',
            'applied' => 0, 'not_applied_reason' => 'older', 'created_at' => '2026-06-15 09:31:00',
        ]);

        $events = self::json($this->api('GET', 'devices/' . $id . '/events', cookie: $this->user))['events'];

        $keys = ['type_label', 'created_at', 'occurred_at', 'received_at', 'applied', 'not_applied_reason', 'not_applied_label'];
        $pick = static fn (array $event): array => array_intersect_key($event, array_flip($keys));
        self::assertSame([
            'type_label' => 'Umbuchung und Statusänderung nicht übernommen',
            'created_at' => '2026-06-15T09:31:00Z',
            'occurred_at' => '2026-06-13T07:00:00Z',
            'received_at' => '2026-06-15T09:31:00Z',
            'applied' => false,
            'not_applied_reason' => 'older',
            'not_applied_label' => 'Neuerer Stand vorhanden',
        ], $pick($events[0]));
        self::assertSame(['from' => null, 'to' => ['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1']], $events[0]['location']);
        self::assertSame(['from' => null, 'from_label' => null, 'to' => 'defective', 'to_label' => 'defekt'], $events[0]['status']);
        self::assertSame([
            'type_label' => 'Umgebucht',
            'created_at' => '2026-06-15T09:30:00Z',
            'occurred_at' => '2026-06-14T08:00:00Z',
            'received_at' => '2026-06-15T09:30:00Z',
            'applied' => true,
            'not_applied_reason' => null,
            'not_applied_label' => null,
        ], $pick($events[1]));
    }

    public function testPagesAndCurrentLocationNames(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->change('state', ['version' => 1, 'location_id' => $this->room]);
        $this->change('state', ['version' => 2, 'status' => 'defective']);
        $this->pdo()->prepare('UPDATE fe_locations SET name = ? WHERE id = ?')->execute(['Fach 1', $this->g1]);

        $page = self::json($this->api('GET', 'devices/' . $id . '/events?limit=2&offset=1'));

        self::assertSame([3, 2, 1], [$page['total'], $page['limit'], $page['offset']]);
        self::assertSame(['Umgebucht', 'Angelegt'], array_column($page['events'], 'type_label'));
        self::assertSame('HLF 20 / Fach 1', $page['events'][0]['location']['from']['path']);
        self::assertSame('HLF 20 / Fach 1', $page['events'][1]['location']['to']['path']);
        self::assertSame([], self::json($this->api('GET', 'devices/' . $id . '/events?offset=3'))['events']);
    }

    public function testAnonymizedUserIsShownAsDeleted(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->change('state', ['version' => 1, 'status' => 'defective'], $this->user);
        new UserRepository($this->pdo())->anonymize($this->userId);

        $events = self::json($this->api('GET', 'devices/' . $id . '/events'))['events'];

        self::assertSame(['id' => $this->userId, 'name' => sprintf('Gelöschter Benutzer #%d', $this->userId)], $events[0]['user']);
        self::assertSame(['id' => $this->adminId, 'name' => 'Kommandant'], $events[1]['user']);
    }

    public function testRetiredDeviceHasHistory(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->change('retire', ['version' => 1]);

        $events = self::json($this->api('GET', 'devices/' . $id . '/events', cookie: $this->user))['events'];

        self::assertSame(['Ausgesondert', 'Angelegt'], array_column($events, 'type_label'));
    }

    public function testForeignOrUnknownDeviceAndInvalidParameters(): void
    {
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);
        $id = $this->createViaApi('MA-01')['id'];

        self::assertError(404, 'not_found', $this->api('GET', 'devices/' . $foreign . '/events'));
        self::assertError(404, 'not_found', $this->api('GET', 'devices/999999999/events'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/' . $id . '/events?limit=201'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices/' . $id . '/events?offset=-1'));
        self::assertError(405, 'method_not_allowed', $this->api('POST', 'devices/' . $id . '/events', []));
    }

    /**
     * Ändert das zuletzt über die API angelegte Gerät.
     *
     * @param array<string, mixed> $body
     */
    private function change(string $action, array $body, ?string $cookie = null): void
    {
        $id = (int) $this->pdo()->query('SELECT MAX(id) FROM fe_devices')->fetchColumn();
        $response = $this->api('POST', 'devices/' . $id . '/' . $action, $body, $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
    }
}
