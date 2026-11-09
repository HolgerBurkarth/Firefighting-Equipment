<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Maintenance\MaintenanceListHandler;
use FirefightingEquipment\Api\V1\Maintenance\MaintenanceMoveHandler;
use FirefightingEquipment\Permission\Area;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Seite „Wartung“ (Phase 2, P2.4): GET maintenance (Stufen Sammelstelle und Werkstatt) und die
 * Sammelaktionen POST maintenance/to-workshop und …/back. Rechte der Routen:
 * TenantRoutePermissionsTest.
 */
#[CoversClass(MaintenanceListHandler::class)]
#[CoversClass(MaintenanceMoveHandler::class)]
#[CoversClass(Area::class)]
final class MaintenanceTest extends DeviceApiTestCase
{
    use FilteredRoles;

    private int $collection;
    private int $collectionBox;
    private int $workshop;
    private int $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = $this->createLocation($this->tenantId, ['name' => 'Sammelstelle', 'kind' => 'room']);
        $this->collectionBox = $this->createSubLocation($this->tenantId, $this->collection, ['name' => 'Kiste', 'kind' => 'room']);
        $this->workshop = $this->createLocation($this->tenantId, ['name' => 'Werkstatt', 'kind' => 'room']);
        $this->store = $this->createLocation($this->tenantId, ['name' => 'Hauptlager', 'kind' => 'room']);
    }

    private function enableTasks(bool $withStore = true, bool $withWorkshop = true): void
    {
        foreach (['collection', 'workshop', 'store'] as $task) {
            $this->insert('fe_tenant_location_tasks', ['tenant_id' => $this->tenantId, 'task' => $task, 'enabled' => 1]);
        }
        $this->pdo()->prepare("UPDATE fe_locations SET task = 'collection', collection_accepts = 'both' WHERE id = ?")->execute([$this->collection]);
        if ($withWorkshop) {
            $this->pdo()->prepare("UPDATE fe_locations SET task = 'workshop' WHERE id = ?")->execute([$this->workshop]);
        }
        if ($withStore) {
            $this->pdo()->prepare("UPDATE fe_locations SET task = 'store', is_default_store = 1 WHERE id = ?")->execute([$this->store]);
        }
    }

    /** @param list<array<string, mixed>> $devices */
    private static function entries(array $devices): array
    {
        return array_map(static fn (array $device): array => ['id' => $device['id'], 'version' => $device['version']], $devices);
    }

    public function testAreaExistsOnlyWithAnEnabledCollectionOrWorkshopTask(): void
    {
        self::assertSame(403, $this->api('GET', 'maintenance')->statusCode(), 'ohne freigeschaltete Aufgabe gibt es den Bereich nicht');
        $me = self::json($this->api('GET', 'auth/me'))['permissions'];
        self::assertArrayNotHasKey('maintenance', (array) $me['areas']);
        $versionBefore = $me['version'];

        $this->enableTasks();

        self::assertSame(200, $this->api('GET', 'maintenance')->statusCode());
        $after = self::json($this->api('GET', 'auth/me'))['permissions'];
        self::assertSame('write', ((array) $after['areas'])['maintenance']);
        self::assertNotSame($versionBefore, $after['version'], 'neue Rechte, die App lädt sie neu');
    }

    public function testStandardRoleUserHasNoAccess(): void
    {
        $this->enableTasks();

        self::assertSame(403, $this->api('GET', 'maintenance', null, $this->user)->statusCode());
        self::assertSame(403, $this->api('POST', 'maintenance/back', ['devices' => []], $this->user)->statusCode());
    }

    public function testRoleWithDataFilterHasNoAccess(): void
    {
        $this->enableTasks();
        $this->filteredAccount('filter@example.org', ['filter_locations' => 1, 'filter_device_types' => 0], [$this->hlf], [], ['maintenance' => 'write']);
        $cookie = $this->loginCookie('immendingen', 'filter@example.org');

        self::assertSame(403, $this->api('GET', 'maintenance', null, $cookie)->statusCode(), 'F3');
    }

    public function testListShowsBothStagesOldestFirst(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1', ['location_id' => $this->collection]);
        $b = $this->createViaApi('B-2', ['location_id' => $this->collectionBox, 'status' => 'defective']);
        $c = $this->createViaApi('C-3', ['location_id' => $this->workshop, 'status' => 'maintenance']);
        $this->createViaApi('D-4');
        $this->createViaApi('E-5', ['location_id' => $this->collection]);
        $this->api('POST', 'devices/' . self::json($this->api('GET', 'devices?q=E-5'))['devices'][0]['id'] . '/retire', ['version' => 1]);
        // B ist am längsten dort.
        $this->pdo()->prepare('UPDATE fe_devices SET state_changed_at = UTC_TIMESTAMP() - INTERVAL 3 DAY WHERE id = ?')->execute([$b['id']]);

        $response = $this->api('GET', 'maintenance');

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response);
        self::assertSame(['Sammelstelle', 2, [$this->collection]], [$body['collection']['label'], $body['collection']['total'], array_column($body['collection']['locations'], 'id')]);
        self::assertSame(['B-2', 'A-1'], array_column($body['collection']['devices'], 'identifier'), 'am längsten wartend zuerst, Fach eingeschlossen, ausgesondert nicht');
        self::assertSame(['Werkstatt', 1, ['C-3']], [$body['workshop']['label'], $body['workshop']['total'], array_column($body['workshop']['devices'], 'identifier')]);
        self::assertSame(['id' => $this->store, 'name' => 'Hauptlager'], $body['default_store']);
        self::assertSame($c['id'], $body['workshop']['devices'][0]['id']);
        self::assertSame($a['version'], $body['collection']['devices'][1]['version']);
        self::assertArrayHasKey('state_changed_at', $body['collection']['devices'][0]);
    }

    public function testListUsesTheRenamedTaskLabelAndEmptyStages(): void
    {
        $this->enableTasks(withStore: false, withWorkshop: false);
        $this->pdo()->prepare("UPDATE fe_tenant_location_tasks SET label = 'Sammelkiste' WHERE task = 'collection'")->execute();

        $body = self::json($this->api('GET', 'maintenance'));

        self::assertSame('Sammelkiste', $body['collection']['label']);
        self::assertSame(['Werkstatt', [], 0, null], [$body['workshop']['label'], $body['workshop']['locations'], $body['workshop']['total'], $body['default_store']]);
    }

    public function testToWorkshopBooksStatusMaintenanceWithOneEventEach(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1', ['location_id' => $this->collection, 'status' => 'defective']);
        $b = $this->createViaApi('B-2', ['location_id' => $this->collectionBox, 'status' => 'defective']);

        $response = $this->api('POST', 'maintenance/to-workshop', ['devices' => self::entries([$a, $b]), 'comment' => 'Transport Montag']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $body = self::json($response);
        self::assertSame(['total' => 2, 'applied' => 2, 'unchanged' => 0, 'errors' => 0], $body['summary']);
        self::assertSame(['id' => $this->workshop, 'name' => 'Werkstatt'], $body['target']);
        foreach ($body['results'] as $result) {
            self::assertSame(['applied', 'maintenance', $this->workshop], [$result['outcome'], $result['device']['status'], $result['device']['location']['id']]);
        }
        self::assertSame(['state_changed', $this->adminId, $this->collection, $this->workshop, 'defective', 'maintenance', 'Transport Montag'], self::state($this->events($a['id'])[1]));
        self::assertSame(['state_changed', $this->adminId, $this->collectionBox, $this->workshop, 'defective', 'maintenance', 'Transport Montag'], self::state($this->events($b['id'])[1]));
    }

    public function testToWorkshopReportsErrorsPerDeviceAndKeepsTheRest(): void
    {
        $this->enableTasks();
        $ok = $this->createViaApi('A-1', ['location_id' => $this->collection]);
        $stale = $this->createViaApi('B-2', ['location_id' => $this->collection]);
        $singleType = $this->createDeviceType($this->tenantId, ['name' => 'Einmalfilter']);
        $this->pdo()->prepare('UPDATE fe_device_types SET single_use = 1 WHERE id = ?')->execute([$singleType]);
        $single = $this->createViaApi('C-3', ['location_id' => $this->collection, 'device_type_id' => $singleType]);
        $entries = self::entries([$ok, $stale, $single]);
        $entries[1]['version'] = 7;

        $body = self::json($this->api('POST', 'maintenance/to-workshop', ['devices' => $entries]));

        self::assertSame(['total' => 3, 'applied' => 1, 'unchanged' => 0, 'errors' => 2], $body['summary']);
        self::assertSame(['applied', 'error', 'error'], array_column($body['results'], 'outcome'));
        self::assertSame('version_conflict', $body['results'][1]['error']['error']);
        self::assertSame(['rule_violation', 'Einmalgeräte werden nicht gewartet, sondern ersetzt.'], [$body['results'][2]['error']['error'], $body['results'][2]['error']['message']]);
        self::assertCount(1, $this->events($single['id']));
    }

    public function testBackGoesToTheDefaultStoreAsReady(): void
    {
        $this->enableTasks();
        $a = $this->createViaApi('A-1', ['location_id' => $this->workshop, 'status' => 'maintenance']);

        $body = self::json($this->api('POST', 'maintenance/back', ['devices' => self::entries([$a])]));

        self::assertSame(['applied', 'ready', $this->store], [$body['results'][0]['outcome'], $body['results'][0]['device']['status'], $body['results'][0]['device']['location']['id']]);
        self::assertSame(['state_changed', $this->adminId, $this->workshop, $this->store, 'maintenance', 'ready', null], self::state($this->events($a['id'])[1]));
    }

    public function testNothingChangesWithoutTargetOrWithBadBodies(): void
    {
        $this->enableTasks(withStore: false, withWorkshop: false);
        $a = $this->createViaApi('A-1', ['location_id' => $this->collection]);
        $before = $this->deviceSnapshot();

        foreach (['maintenance/to-workshop', 'maintenance/back'] as $path) {
            $response = $this->api('POST', $path, ['devices' => self::entries([$a])]);
            self::assertSame(409, $response->statusCode(), $path);
            self::assertSame('rule_violation', self::json($response)['error']);
        }
        self::assertSame($before, $this->deviceSnapshot());

        $this->pdo()->prepare("UPDATE fe_locations SET task = 'workshop' WHERE id = ?")->execute([$this->workshop]);
        foreach ([
            ['devices' => []],
            ['devices' => [['id' => $a['id'], 'version' => 1], ['id' => $a['id'], 'version' => 1]]],
            ['devices' => [['id' => $a['id'], 'version' => 1, 'status' => 'ready']]],
            ['devices' => [['id' => $a['id']]]],
            ['devices' => self::entries([$a]), 'target' => 5],
        ] as $body) {
            self::assertSame(400, $this->api('POST', 'maintenance/to-workshop', $body)->statusCode(), json_encode($body, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testUnknownAndForeignDevicesAreReportedAsNotFound(): void
    {
        $this->enableTasks();
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);

        $body = self::json($this->api('POST', 'maintenance/to-workshop', ['devices' => [['id' => $foreign, 'version' => 1]]]));

        self::assertSame(['error', 'not_found', null], [$body['results'][0]['outcome'], $body['results'][0]['error']['error'], $body['results'][0]['device']]);
    }
}
