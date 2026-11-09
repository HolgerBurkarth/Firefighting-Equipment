<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceBulkHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceStateHandler;
use FirefightingEquipment\Device\SingleUseGuard;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * „Benutzt melden“ und Einmalgeräte (Phase 2, P2.3): POST devices/{id}/used (Aktion device.used),
 * Ziel Sammelstelle (auch außerhalb des Datenfilters, G4), Einschränkung „Bei Einmalgeräten nur
 * benutzt melden“ und der Schalter am Ausrüstungstyp. Rechte der Routen: TenantRoutePermissionsTest.
 */
#[CoversClass(DeviceStateHandler::class)]
#[CoversClass(DeviceBulkHandler::class)]
#[CoversClass(SingleUseGuard::class)]
final class DeviceUsedTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $collection;
    private int $singleUseType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = $this->createLocation($this->tenantId, ['name' => 'Sammelstelle', 'kind' => 'room']);
        $this->singleUseType = $this->createDeviceType($this->tenantId, ['name' => 'Einmalfilter']);
        $this->pdo()->prepare('UPDATE fe_device_types SET single_use = 1 WHERE id = ?')->execute([$this->singleUseType]);
    }

    private function makeCollection(string $accepts): void
    {
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'collection', collection_accepts = ? WHERE id = ?")->execute([$accepts, $this->collection]);
    }

    /**
     * Konto mit device.used (und optional der Einschränkung), ohne Datenfilter.
     *
     * @param list<string> $extraActions
     */
    private function usedAccount(string $email, bool $restricted, bool $withUsed = true, array $extraActions = []): string
    {
        $roleId = $this->insert('fe_roles', ['tenant_id' => $this->tenantId, 'name' => 'Träger ' . $email, 'restrict_single_use' => (int) $restricted]);
        $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'search', 'level' => 'read']);
        if ($extraActions !== []) {
            $this->insert('fe_role_areas', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'area' => 'devices', 'level' => 'write']);
        }
        foreach (['book.move', 'book.status', ...($withUsed ? ['device.used'] : []), ...$extraActions] as $action) {
            $this->insert('fe_role_actions', ['role_id' => $roleId, 'tenant_id' => $this->tenantId, 'action' => $action]);
        }
        $this->account('immendingen', $email, ['role_id' => $roleId]);

        return $this->loginCookie('immendingen', $email);
    }

    public function testWithoutCollectionPointOnlyTheStatusChanges(): void
    {
        $device = $this->createViaApi('MA-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1, 'comment' => 'Einsatz']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response)['device'];
        self::assertSame(['used', $this->g1, 2], [$body['status'], $body['location']['id'], $body['version']]);
        self::assertSame(['state_changed', $this->adminId, null, null, 'ready', 'used', 'Einsatz'], self::state($this->events($device['id'])[1]));
    }

    public function testBooksIntoTheCollectionPointThatAcceptsUsed(): void
    {
        $this->makeCollection('used');
        $device = $this->createViaApi('MA-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1]);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['used', $this->collection], [self::json($response)['device']['status'], self::json($response)['device']['location']['id']]);
        self::assertSame(['state_changed', $this->adminId, $this->g1, $this->collection, 'ready', 'used', null], self::state($this->events($device['id'])[1]));
    }

    public function testCollectionPointForDefectiveOnlyIsNotTheTarget(): void
    {
        $this->makeCollection('defective');
        $device = $this->createViaApi('MA-01');

        $body = self::json($this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1]))['device'];

        self::assertSame($this->g1, $body['location']['id']);
    }

    public function testLockedCollectionPointIsNotTheTarget(): void
    {
        $this->makeCollection('both');
        $this->pdo()->prepare('UPDATE fe_locations SET active = 0 WHERE id = ?')->execute([$this->collection]);
        $device = $this->createViaApi('MA-01');

        self::assertSame($this->g1, self::json($this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1]))['device']['location']['id']);
    }

    public function testOnlyVersionCommentAndBookingFieldsAreAccepted(): void
    {
        $device = $this->createViaApi('MA-01');

        foreach (['location_id' => $this->room, 'status' => 'defective'] as $field => $value) {
            $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1, $field => $value]);
            self::assertSame(400, $response->statusCode(), $field);
        }
        self::assertCount(1, $this->events($device['id']));
    }

    public function testRepeatedWithTheSameClientRefIsReplayed(): void
    {
        $device = $this->createViaApi('MA-01');
        $ref = '6f0a1f4e-3d6c-4c1e-9b51-0e2a4f6b7c8d';

        $first = self::json($this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1, 'client_ref' => $ref]));
        $again = self::json($this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1, 'client_ref' => $ref]));

        self::assertFalse($first['result']['replayed']);
        self::assertTrue($again['result']['replayed']);
        self::assertCount(2, $this->events($device['id']));
    }

    public function testOfflineBookingWithActionTimeIsAccepted(): void
    {
        $device = $this->createViaApi('MA-01');
        $at = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1, 'client_ref' => 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d', 'occurred_at' => $at]);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['applied', 'used'], [self::json($response)['result']['outcome'], self::json($response)['device']['status']]);
    }

    public function testRoleWithoutTheActionGets403(): void
    {
        $device = $this->createViaApi('MA-01');
        $cookie = $this->usedAccount('ohne@example.org', false, withUsed: false);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1], $cookie);

        self::assertSame(403, $response->statusCode());
        self::assertCount(1, $this->events($device['id']));
    }

    public function testFilteredRoleReachesTheCollectionPointOutsideItsFilterAndThenLosesSight(): void
    {
        $this->makeCollection('both');
        $device = $this->createViaApi('MA-01');
        $this->filteredAccount('filter@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], extraActions: ['device.used']);
        $cookie = $this->loginCookie('immendingen', 'filter@example.org');

        // Freies Umbuchen in die Sammelstelle bleibt im Filter: wie unbekannt.
        $moved = $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'location_id' => $this->collection], $cookie);
        self::assertSame(400, $moved->statusCode(), $moved->body());

        $response = $this->api('POST', 'devices/' . $device['id'] . '/used', ['version' => 1], $cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['used', $this->collection], [self::json($response)['device']['status'], self::json($response)['device']['location']['id']]);
        self::assertSame(404, $this->api('GET', 'devices/' . $device['id'], null, $cookie)->statusCode());
    }

    public function testRestrictedRoleMayNotBookSingleUseDevices(): void
    {
        $single = $this->createViaApi('EF-01', ['device_type_id' => $this->singleUseType]);
        $normal = $this->createViaApi('MA-01');
        $cookie = $this->usedAccount('traeger@example.org', true);
        $before = $this->deviceSnapshot();

        foreach (['location_id' => $this->room, 'status' => 'defective'] as $field => $value) {
            $response = $this->api('POST', 'devices/' . $single['id'] . '/state', ['version' => 1, $field => $value], $cookie);
            self::assertSame(403, $response->statusCode(), $response->body());
            self::assertSame('restricted_action', self::json($response)['error']);
        }
        self::assertSame($before, $this->deviceSnapshot());

        self::assertSame(200, $this->api('POST', 'devices/' . $normal['id'] . '/state', ['version' => 1, 'status' => 'maintenance'], $cookie)->statusCode());
        self::assertSame(200, $this->api('POST', 'devices/' . $single['id'] . '/used', ['version' => 1], $cookie)->statusCode());
    }

    public function testUnrestrictedRoleMayBookSingleUseDevices(): void
    {
        $single = $this->createViaApi('EF-01', ['device_type_id' => $this->singleUseType]);
        $cookie = $this->usedAccount('frei@example.org', false);

        self::assertSame(200, $this->api('POST', 'devices/' . $single['id'] . '/state', ['version' => 1, 'status' => 'defective'], $cookie)->statusCode());
    }

    public function testBulkReportsTheRestrictionPerEntry(): void
    {
        $single = $this->createViaApi('EF-01', ['device_type_id' => $this->singleUseType]);
        $normal = $this->createViaApi('MA-01');
        $cookie = $this->usedAccount('traeger@example.org', true, extraActions: ['devices.bulk']);

        $response = $this->api('POST', 'devices/bulk/state', ['devices' => [
            ['id' => $single['id'], 'version' => 1, 'status' => 'maintenance'],
            ['id' => $normal['id'], 'version' => 1, 'status' => 'maintenance'],
        ]], $cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        $results = self::json($response)['results'];
        self::assertSame(['error', 'applied'], array_column($results, 'outcome'));
        self::assertSame('restricted_action', $results[0]['error']['error']);
    }

    public function testDeviceTypeSwitchAndJson(): void
    {
        $response = $this->api('POST', 'device-types/' . $this->typeId . '/single-use', ['single_use' => true]);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertTrue(self::json($response)['device_type']['single_use']);

        $device = $this->createViaApi('MA-01');
        self::assertTrue($device['device_type']['single_use']);
        $list = self::json($this->api('GET', 'devices'))['devices'];
        self::assertTrue($list[0]['device_type']['single_use']);

        self::assertFalse(self::json($this->api('POST', 'device-types/' . $this->typeId . '/single-use', ['single_use' => false]))['device_type']['single_use']);
        self::assertSame(400, $this->api('POST', 'device-types/' . $this->typeId . '/single-use', ['single_use' => 'ja'])->statusCode());
        self::assertSame(400, $this->api('POST', 'device-types/' . $this->typeId . '/single-use', [])->statusCode());
        self::assertSame(404, $this->api('POST', 'device-types/' . $this->otherTypeId . '/single-use', ['single_use' => true])->statusCode());
    }

    public function testPermissionsListTheRestriction(): void
    {
        $restricted = $this->usedAccount('traeger@example.org', true);
        $free = $this->usedAccount('frei@example.org', false);

        $me = self::json($this->api('GET', 'auth/me', null, $restricted))['permissions'];
        self::assertSame(['single_use_used_only'], $me['restrictions']);
        self::assertContains('device.used', $me['actions']);
        $freeMe = self::json($this->api('GET', 'auth/me', null, $free))['permissions'];
        self::assertSame([], $freeMe['restrictions']);
        self::assertSame([], self::json($this->api('GET', 'auth/me'))['permissions']['restrictions']);
        self::assertNotSame($me['version'], $freeMe['version']);
    }
}
