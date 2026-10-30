<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceHistoryListHandler;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceHistoryEntry;
use FirefightingEquipment\Device\DeviceHistoryPage;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * GET device-events (Admin): Historie der ganzen Stadt, neueste zuerst, seitenweise, je Eintrag wie
 * GET devices/{id}/events mit dem Gerät (aktuelle Kennung und aktueller Typ). Rechte in
 * AdminRoutesAccessTest.
 */
#[CoversClass(DeviceHistoryListHandler::class)]
#[CoversClass(DeviceEventRepository::class)]
#[CoversClass(DeviceHistoryEntry::class)]
#[CoversClass(DeviceHistoryPage::class)]
final class DeviceHistoryListTest extends DeviceApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Kommandant', $this->adminId]);
        $this->pdo()->prepare('UPDATE fe_users SET display_name = ? WHERE id = ?')->execute(['Gerätewart', $this->userId]);
    }

    public function testAllDevicesOfTenantNewestFirstWithDevice(): void
    {
        $mask = $this->createViaApi('MA-01', ['comment' => 'Neu beschafft']);
        $radio = $this->createViaApi('HRT 5');
        $this->post($radio['id'], 'state', ['version' => 1, 'status' => 'defective', 'comment' => 'Akku kaputt'], $this->user);
        $this->post($mask['id'], 'state', ['version' => 1, 'location_id' => $this->room], $this->user);
        $this->post($mask['id'], 'retire', ['version' => 2]);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);
        $this->insert('fe_device_events', [
            'tenant_id' => $this->otherTenantId, 'device_id' => (int) $this->pdo()->query('SELECT MAX(id) FROM fe_devices')->fetchColumn(),
            'user_id' => $this->account('tuttlingen', 'fremd@example.org'), 'event_type' => 'created',
            'to_location_id' => $this->otherLocation, 'to_status' => 'ready',
        ]);

        $response = $this->api('GET', 'device-events');

        self::assertSame(200, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertSame([5, 50, 0], [$json['total'], $json['limit'], $json['offset']]);
        $type = ['id' => $this->typeId, 'name' => 'Atemschutzmaske'];
        self::assertSame([
            ['Ausgesondert', 'Kommandant', ['id' => $mask['id'], 'identifier' => 'MA-01', 'device_type' => $type], null],
            ['Umgebucht', 'Gerätewart', ['id' => $mask['id'], 'identifier' => 'MA-01', 'device_type' => $type], null],
            ['Status geändert', 'Gerätewart', ['id' => $radio['id'], 'identifier' => 'HRT 5', 'device_type' => $type], 'Akku kaputt'],
            ['Angelegt', 'Kommandant', ['id' => $radio['id'], 'identifier' => 'HRT 5', 'device_type' => $type], null],
            ['Angelegt', 'Kommandant', ['id' => $mask['id'], 'identifier' => 'MA-01', 'device_type' => $type], 'Neu beschafft'],
        ], array_map(static fn (array $e): array => [$e['type_label'], $e['user']['name'], $e['device'], $e['comment']], $json['events']));

        // Jeder Eintrag enthält alle Felder von GET devices/{id}/events, dazu das Gerät.
        $single = self::json($this->api('GET', 'devices/' . $mask['id'] . '/events'))['events'];
        $ofMask = array_values(array_filter($json['events'], static fn (array $e): bool => $e['device']['id'] === $mask['id']));
        self::assertSame($single, array_map(static function (array $e): array {
            unset($e['device']);

            return $e;
        }, $ofMask));
        self::assertSame(
            ['from' => ['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'], 'to' => ['id' => $this->room, 'name' => 'Lager', 'path' => 'Lager']],
            $json['events'][1]['location'],
        );
    }

    public function testSameSecondOrderedByIdAndCurrentDeviceData(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        foreach (['ready', 'defective', 'maintenance'] as $status) {
            $this->insert('fe_device_events', [
                'tenant_id' => $this->tenantId, 'device_id' => $id, 'user_id' => $this->userId, 'event_type' => 'state_changed',
                'from_status' => $status === 'ready' ? 'maintenance' : 'ready', 'to_status' => $status, 'created_at' => '2026-06-15 09:30:00',
            ]);
        }
        $this->insert('fe_device_events', [
            'tenant_id' => $this->tenantId, 'device_id' => $id, 'user_id' => $this->userId, 'event_type' => 'state_changed',
            'from_status' => 'ready', 'to_status' => 'defective', 'created_at' => '2026-06-14 07:00:00',
        ]);
        $newType = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->pdo()->prepare('UPDATE fe_devices SET identifier = ?, device_type_id = ? WHERE id = ?')->execute(['MA-01a', $newType, $id]);

        $events = self::json($this->api('GET', 'device-events'))['events'];

        self::assertSame(
            [['maintenance', '2026-06-15T09:30:00Z'], ['defective', '2026-06-15T09:30:00Z'], ['ready', '2026-06-15T09:30:00Z'], ['defective', '2026-06-14T07:00:00Z']],
            array_map(static fn (array $e): array => [$e['status']['to'], $e['created_at']], $events),
        );
        self::assertSame(['id' => $id, 'identifier' => 'MA-01a', 'device_type' => ['id' => $newType, 'name' => 'Funkgerät']], $events[0]['device']);
    }

    public function testPagesAndAnonymizedUser(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->post($id, 'state', ['version' => 1, 'location_id' => $this->room], $this->user);
        $this->post($id, 'state', ['version' => 2, 'status' => 'defective'], $this->user);
        new UserRepository($this->pdo())->anonymize($this->userId);

        $page = self::json($this->api('GET', 'device-events?limit=2&offset=1'));

        self::assertSame([3, 2, 1], [$page['total'], $page['limit'], $page['offset']]);
        self::assertSame(['Umgebucht', 'Angelegt'], array_column($page['events'], 'type_label'));
        self::assertSame(['id' => $this->userId, 'name' => sprintf('Gelöschter Benutzer #%d', $this->userId)], $page['events'][0]['user']);
        self::assertSame([], self::json($this->api('GET', 'device-events?offset=3'))['events']);
    }

    public function testEmptyHistoryAndInvalidParameters(): void
    {
        self::assertSame(['events' => [], 'total' => 0, 'limit' => 10, 'offset' => 0], self::json($this->api('GET', 'device-events?limit=10')));
        self::assertError(400, 'invalid_request', $this->api('GET', 'device-events?limit=0'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'device-events?limit=201'));
        self::assertError(400, 'invalid_request', $this->api('GET', 'device-events?offset=-1'));
        self::assertError(405, 'method_not_allowed', $this->api('POST', 'device-events', []));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(int $id, string $action, array $body, ?string $cookie = null): void
    {
        $response = $this->api('POST', 'devices/' . $id . '/' . $action, $body, $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());
    }
}
