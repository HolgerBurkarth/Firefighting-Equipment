<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Locations\LocationTasksHandler;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Device\LocationTaskRepository;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Aufgaben von Standorten (Phase 2, P2.2): Freischalten und Umbenennen durch den Super-Admin,
 * Vergeben durch den Admin an Standorte der Ebene 1, Standard-Lager, Annahme der Sammelstelle.
 * Rechte der Routen: TenantRoutePermissionsTest, SuperAdminRoutesAccessTest.
 */
#[CoversClass(LocationTasksHandler::class)]
#[CoversClass(LocationTaskRepository::class)]
#[CoversClass(LocationRepository::class)]
final class LocationTaskTest extends AuthTestCase
{
    use DeviceRows;

    private int $tenantId;
    private string $admin;
    private string $chef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->tenant('immendingen')->id;
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->account(null, 'chef@example.org');
        $this->admin = $this->loginCookie('immendingen', 'admin@example.org');
        $this->chef = $this->loginCookie(null, 'chef@example.org');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function service(string $method, array|null $body = null): \FirefightingEquipment\Http\Response
    {
        return $this->send($method, self::url('service', 'tenants/' . $this->tenantId . '/location-tasks'), $body ?? ($method === 'POST' ? [] : null), cookie: $this->chef);
    }

    public function testNothingIsEnabledAtFirstAndLabelsAreTheDefaults(): void
    {
        $response = $this->service('GET');

        self::assertSame(200, $response->statusCode(), $response->body());
        $tasks = json_decode($response->body(), true)['tasks'];
        self::assertSame(['store', 'collection', 'workshop', 'returns', 'borrower'], array_column($tasks, 'task'));
        self::assertSame(['Lager', 'Sammelstelle', 'Werkstatt', 'Rückgabe', 'Ausgeliehen an'], array_column($tasks, 'label'));
        self::assertSame([false, false, false, false, false], array_column($tasks, 'enabled'));
    }

    public function testSuperAdminEnablesAndRenames(): void
    {
        $response = $this->service('POST', ['tasks' => [
            ['task' => 'store', 'enabled' => true],
            ['task' => 'collection', 'enabled' => true, 'label' => '  Kiste  '],
        ]]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $tasks = json_decode($response->body(), true)['tasks'];
        self::assertSame([true, true, false], array_slice(array_column($tasks, 'enabled'), 0, 3));
        self::assertSame(['Lager', 'Kiste'], array_slice(array_column($tasks, 'label'), 0, 2));
        self::assertSame('Sammelstelle', $tasks[1]['default_label']);

        // Der Admin sieht dieselben Aufgaben.
        $mine = $this->send('GET', self::url('immendingen', 'location-tasks'), cookie: $this->admin);
        self::assertSame('Kiste', json_decode($mine->body(), true)['tasks'][1]['label']);
    }

    public function testInvalidBodiesAreRejectedBeforeAnythingChanges(): void
    {
        foreach ([
            [['tasks' => []], 'nicht leere Liste'],
            [['tasks' => [['task' => 'store', 'enabled' => true], ['task' => 'store', 'enabled' => false]]], 'doppelt'],
            [['tasks' => [['task' => 'store', 'enabled' => true], ['task' => 'nope', 'enabled' => true]]], 'unbekannt'],
            [['tasks' => [['task' => 'store', 'enabled' => 'yes']]], 'true oder false'],
            [['tasks' => [['task' => 'store', 'enabled' => true, 'label' => str_repeat('x', 51)]]], 'höchstens 50'],
        ] as [$body, $message]) {
            $response = $this->service('POST', $body);
            self::assertSame(400, $response->statusCode(), $response->body());
            self::assertStringContainsString($message, $response->body());
        }
        self::assertSame([false], array_unique(array_column(json_decode($this->service('GET')->body(), true)['tasks'], 'enabled')));
    }

    public function testAdminSetsTasksOnlyOnEnabledTasksAndTopLevel(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);
        $room = $this->createLocation($this->tenantId, ['name' => 'Gerätehaus', 'kind' => 'room']);

        $notEnabled = $this->send('POST', self::url('immendingen', 'locations/' . $room . '/task'), ['task' => 'store'], cookie: $this->admin);
        self::assertSame(409, $notEnabled->statusCode());
        self::assertStringContainsString('nicht freigeschaltet', $notEnabled->body());

        $this->service('POST', ['tasks' => [['task' => 'store', 'enabled' => true], ['task' => 'collection', 'enabled' => true]]]);

        $sub = $this->send('POST', self::url('immendingen', 'locations/' . $fach . '/task'), ['task' => 'store'], cookie: $this->admin);
        self::assertSame(409, $sub->statusCode());
        self::assertStringContainsString('obersten Ebene', $sub->body());

        $ok = $this->send('POST', self::url('immendingen', 'locations/' . $room . '/task'), ['task' => 'store'], cookie: $this->admin);
        self::assertSame(200, $ok->statusCode(), $ok->body());
        $location = json_decode($ok->body(), true)['location'];
        // Das erste Lager wird zum Standard-Lager.
        self::assertSame(['store', true, null], [$location['task'], $location['is_default_store'], $location['collection_accepts']]);

        $collection = $this->send('POST', self::url('immendingen', 'locations/' . $hlf . '/task'), ['task' => 'collection', 'collection_accepts' => 'defective'], cookie: $this->admin);
        self::assertSame(['collection', false, 'defective'], [json_decode($collection->body(), true)['location']['task'], json_decode($collection->body(), true)['location']['is_default_store'], json_decode($collection->body(), true)['location']['collection_accepts']]);

        $default = $this->send('POST', self::url('immendingen', 'locations/' . $hlf . '/task'), ['task' => 'collection'], cookie: $this->admin);
        self::assertSame('both', json_decode($default->body(), true)['location']['collection_accepts']);

        // Ohne Angabe von task: 400; ungültige Annahme: 400.
        self::assertSame(400, $this->send('POST', self::url('immendingen', 'locations/' . $hlf . '/task'), [], cookie: $this->admin)->statusCode());
        self::assertSame(400, $this->send('POST', self::url('immendingen', 'locations/' . $hlf . '/task'), ['task' => 'collection', 'collection_accepts' => 'all'], cookie: $this->admin)->statusCode());
        self::assertSame(400, $this->send('POST', self::url('immendingen', 'locations/' . $hlf . '/task'), ['task' => 'garage'], cookie: $this->admin)->statusCode());

        // Die Liste der Standorte zeigt die Aufgabe.
        $list = json_decode($this->send('GET', self::url('immendingen', 'locations'), cookie: $this->admin)->body(), true)['locations'];
        self::assertSame(['store', 'collection'], array_column($list, 'task'));
    }

    public function testOnlyOneDefaultStoreAndRemovingTheTask(): void
    {
        $this->service('POST', ['tasks' => [['task' => 'store', 'enabled' => true]]]);
        $a = $this->createLocation($this->tenantId, ['name' => 'Lager A', 'kind' => 'room']);
        $b = $this->createLocation($this->tenantId, ['name' => 'Lager B', 'kind' => 'room']);
        $url = static fn (int $id): string => self::url('immendingen', 'locations/' . $id . '/task');

        $this->send('POST', $url($a), ['task' => 'store'], cookie: $this->admin);
        $second = json_decode($this->send('POST', $url($b), ['task' => 'store'], cookie: $this->admin)->body(), true)['location'];
        self::assertFalse($second['is_default_store']);

        $switched = json_decode($this->send('POST', $url($b), ['task' => 'store', 'is_default_store' => true], cookie: $this->admin)->body(), true)['location'];
        self::assertTrue($switched['is_default_store']);
        $rows = array_column(json_decode($this->send('GET', self::url('immendingen', 'locations'), cookie: $this->admin)->body(), true)['locations'], 'is_default_store', 'name');
        self::assertSame(['Lager A' => false, 'Lager B' => true], $rows);

        $removed = json_decode($this->send('POST', $url($b), ['task' => null], cookie: $this->admin)->body(), true)['location'];
        self::assertSame([null, false], [$removed['task'], $removed['is_default_store']]);
    }

    public function testTaskCannotBeSwitchedOffWhileInUse(): void
    {
        $this->service('POST', ['tasks' => [['task' => 'workshop', 'enabled' => true]]]);
        $room = $this->createLocation($this->tenantId, ['name' => 'Werkstatt', 'kind' => 'room']);
        $this->send('POST', self::url('immendingen', 'locations/' . $room . '/task'), ['task' => 'workshop'], cookie: $this->admin);

        $off = $this->service('POST', ['tasks' => [['task' => 'workshop', 'enabled' => false]]]);

        self::assertSame(409, $off->statusCode(), $off->body());
        self::assertSame(['locations' => 1], json_decode($off->body(), true)['details']);

        $this->send('POST', self::url('immendingen', 'locations/' . $room . '/task'), ['task' => null], cookie: $this->admin);
        self::assertSame(200, $this->service('POST', ['tasks' => [['task' => 'workshop', 'enabled' => false]]])->statusCode());
    }

    public function testTasksAreSeparatePerTenantAndUnknownTenantIs404(): void
    {
        $this->service('POST', ['tasks' => [['task' => 'store', 'enabled' => true]]]);
        $other = $this->send('GET', self::url('service', 'tenants/' . $this->tenant('tuttlingen')->id . '/location-tasks'), cookie: $this->chef);
        self::assertSame([false], array_unique(array_column(json_decode($other->body(), true)['tasks'], 'enabled')));
        self::assertSame(404, $this->send('GET', self::url('service', 'tenants/99999/location-tasks'), cookie: $this->chef)->statusCode());
    }
}
