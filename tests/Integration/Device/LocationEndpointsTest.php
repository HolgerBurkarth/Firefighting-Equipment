<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Locations\LocationActionHandler;
use FirefightingEquipment\Api\V1\Locations\LocationJson;
use FirefightingEquipment\Api\V1\Locations\LocationListHandler;
use FirefightingEquipment\Device\Location;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Standorte über die ganze Anwendung: Baum und einzelner Standort (angemeldet), Anlegen,
 * Umbenennen, Sperren und Entsperren (Admin). Regeln: zwei Ebenen, Art des übergeordneten
 * Standorts, Namen eindeutig je Ebene, Sperren nur leer. Rechte aller Routen: AdminRoutesAccessTest
 * und TenantReadRoutesAccessTest.
 */
#[CoversClass(LocationListHandler::class)]
#[CoversClass(LocationActionHandler::class)]
#[CoversClass(LocationJson::class)]
#[CoversClass(LocationRepository::class)]
#[CoversClass(Location::class)]
#[CoversClass(DeviceErrors::class)]
final class LocationEndpointsTest extends AuthTestCase
{
    use DeviceRows;

    private int $tenantId;
    private int $typeId;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->tenant('immendingen')->id;
        $this->typeId = $this->createDeviceType($this->tenantId, ['name' => 'Atemschutzmaske']);
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->admin = $this->loginCookie('immendingen', 'admin@example.org');
    }

    public function testTreeListsActiveLocationsByNameWithDeviceCounts(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach2 = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 2']);
        $fach1 = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);
        $fach3 = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 3', 'active' => 0]);
        $room = $this->createLocation($this->tenantId, ['name' => 'Geräteraum', 'kind' => 'room']);
        $this->createLocation($this->tenantId, ['name' => 'Altes LF', 'active' => 0]);
        $this->createDevice($this->tenantId, $this->typeId, $hlf);
        $this->createDevice($this->tenantId, $this->typeId, $hlf, ['status' => 'retired']);
        $this->createDevice($this->tenantId, $this->typeId, $fach1);
        $this->createDevice($this->tenantId, $this->typeId, $fach1, ['status' => 'maintenance']);
        // nur per SQL möglich: Gerät an einem gesperrten Standort zählt trotzdem in device_count_total
        $this->createDevice($this->tenantId, $this->typeId, $fach3);
        $other = $this->tenant('tuttlingen')->id;
        $this->createLocation($other, ['name' => 'HLF 10']);
        $this->account('immendingen', 'user@example.org');
        $user = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('GET', self::url('immendingen', 'locations'), cookie: $user);

        self::assertSame(200, $response->statusCode(), $response->body());
        $tree = self::json($response)['locations'];
        self::assertSame(['Geräteraum', 'HLF 20'], array_column($tree, 'name'));
        $vehicle = $tree[1];
        self::assertSame(
            ['id', 'name', 'kind', 'kind_label', 'level', 'parent_id', 'active', 'device_count', 'device_count_total', 'children', 'created_at', 'updated_at'],
            array_keys($vehicle),
        );
        self::assertSame([$hlf, 'vehicle', 'Fahrzeug', 1, null, true, 1, 4], [
            $vehicle['id'], $vehicle['kind'], $vehicle['kind_label'], $vehicle['level'], $vehicle['parent_id'],
            $vehicle['active'], $vehicle['device_count'], $vehicle['device_count_total'],
        ]);
        self::assertSame([$fach1, $fach2], array_column($vehicle['children'], 'id'));
        $child = $vehicle['children'][0];
        self::assertSame(
            ['id', 'name', 'kind', 'kind_label', 'level', 'parent_id', 'active', 'device_count', 'created_at', 'updated_at'],
            array_keys($child),
        );
        self::assertSame([2, $hlf, 'vehicle', 2], [$child['level'], $child['parent_id'], $child['kind'], $child['device_count']]);
        self::assertSame(['room', 'Raum/Lager', 0, 0, []], [
            $tree[0]['kind'], $tree[0]['kind_label'], $tree[0]['device_count'], $tree[0]['device_count_total'], $tree[0]['children'],
        ]);
        self::assertSame($room, $tree[0]['id']);

        $all = self::json($this->send('GET', self::url('immendingen', 'locations?include_inactive=1'), cookie: $user))['locations'];
        self::assertSame(['Altes LF', 'Geräteraum', 'HLF 20'], array_column($all, 'name'));
        self::assertFalse($all[0]['active']);
        self::assertSame(['Fach 1', 'Fach 2', 'Fach 3'], array_column($all[2]['children'], 'name'));
        self::assertSame([true, true, false], array_column($all[2]['children'], 'active'));
        self::assertSame(4, $all[2]['device_count_total']);

        self::assertError(400, 'invalid_request', $this->send('GET', self::url('immendingen', 'locations?include_inactive=2'), cookie: $user));
    }

    public function testShowLocation(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);
        $locked = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 2', 'active' => 0]);
        $this->createDevice($this->tenantId, $this->typeId, $fach);

        $parent = self::json($this->get(sprintf('locations/%d', $hlf)))['location'];
        self::assertSame('HLF 20', $parent['name']);
        self::assertSame([$fach, $locked], array_column($parent['children'], 'id'), 'auch gesperrte Unterstandorte');
        self::assertSame([0, 1], [$parent['device_count'], $parent['device_count_total']]);

        $child = self::json($this->get(sprintf('locations/%d', $fach)))['location'];
        self::assertSame(['Fach 1', 2, $hlf, 1], [$child['name'], $child['level'], $child['parent_id'], $child['device_count']]);
        self::assertArrayNotHasKey('children', $child);
        self::assertArrayNotHasKey('device_count_total', $child);

        $foreign = $this->createLocation($this->tenant('tuttlingen')->id);
        self::assertError(404, 'not_found', $this->get(sprintf('locations/%d', $foreign)));
        self::assertError(404, 'not_found', $this->get('locations/999999'));
    }

    public function testCreateTopLevelLocation(): void
    {
        $response = $this->post('locations', ['name' => ' HLF 20 ', 'kind' => 'vehicle']);

        self::assertSame(201, $response->statusCode(), $response->body());
        $location = self::json($response)['location'];
        self::assertSame(['HLF 20', 'vehicle', 'Fahrzeug', 1, null, true, 0, 0, []], [
            $location['name'], $location['kind'], $location['kind_label'], $location['level'], $location['parent_id'],
            $location['active'], $location['device_count'], $location['device_count_total'], $location['children'],
        ]);

        foreach (['room' => 'Raum/Lager', 'external' => 'Extern'] as $kind => $label) {
            $created = self::json($this->post('locations', ['name' => 'Ort ' . $kind, 'kind' => $kind, 'parent_id' => null]))['location'];
            self::assertSame([$kind, $label], [$created['kind'], $created['kind_label']]);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Name' => [['kind' => 'room'], 'Das Feld "name" fehlt oder ist kein Text.'];
        yield 'leerer Name' => [['name' => ' ', 'kind' => 'room'], 'Der Name darf nicht leer sein.'];
        yield 'Name zu lang' => [['name' => str_repeat('x', 101), 'kind' => 'room'], 'Der Name darf höchstens 100 Zeichen lang sein (angegeben: 101).'];
        yield 'ohne Art' => [['name' => 'Lager'], 'Ein Standort der obersten Ebene braucht eine Art (vehicle, room oder external).'];
        yield 'unbekannte Art' => [['name' => 'Lager', 'kind' => 'Room'], 'Das Feld "kind" muss einer der Werte "vehicle", "room", "external" sein.'];
        yield 'Art als Zahl' => [['name' => 'Lager', 'kind' => 1], 'Das Feld "kind" ist kein Text.'];
        yield 'parent_id als Text' => [['name' => 'Fach', 'parent_id' => '1'], 'Das Feld "parent_id" muss eine ganze Zahl von 1 bis 4294967295 sein.'];
        yield 'parent_id 0' => [['name' => 'Fach', 'parent_id' => 0], 'Das Feld "parent_id" muss eine ganze Zahl von 1 bis 4294967295 sein.'];
        yield 'parent_id unbekannt' => [['name' => 'Fach', 'parent_id' => 999999], 'Der übergeordnete Standort wurde nicht gefunden.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidCreateIsRejected(array $body, string $message): void
    {
        $response = $this->post('locations', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame(0, $this->countRows('fe_locations'));
    }

    public function testForeignParentIsRejectedLikeUnknown(): void
    {
        $foreign = $this->createLocation($this->tenant('tuttlingen')->id);

        $response = $this->post('locations', ['name' => 'Fach', 'parent_id' => $foreign]);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame('Der übergeordnete Standort wurde nicht gefunden.', self::json($response)['message']);
    }

    public function testSubLocationTakesKindOfParent(): void
    {
        $room = $this->createLocation($this->tenantId, ['name' => 'Gerätehaus', 'kind' => 'room']);

        $inherited = self::json($this->post('locations', ['name' => 'Regal 1', 'parent_id' => $room]))['location'];
        self::assertSame(['room', 'Raum/Lager', 2, $room], [$inherited['kind'], $inherited['kind_label'], $inherited['level'], $inherited['parent_id']]);
        self::assertArrayNotHasKey('children', $inherited);

        $matching = $this->post('locations', ['name' => 'Regal 2', 'parent_id' => $room, 'kind' => 'room']);
        self::assertSame(201, $matching->statusCode(), $matching->body());

        $other = $this->post('locations', ['name' => 'Regal 3', 'parent_id' => $room, 'kind' => 'vehicle']);
        self::assertError(409, 'rule_violation', $other);
        self::assertSame('Ein Unterstandort hat immer die Art des übergeordneten Standorts („Gerätehaus“: Raum/Lager).', self::json($other)['message']);
        self::assertSame(3, $this->countRows('fe_locations'));
    }

    public function testAtMostTwoLevels(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);

        $response = $this->post('locations', ['name' => 'Schublade', 'parent_id' => $fach]);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Standorte haben höchstens zwei Ebenen; „Fach 1“ ist bereits ein Unterstandort.', self::json($response)['message']);
        self::assertSame(2, $this->countRows('fe_locations'));
    }

    public function testNoSubLocationUnderLockedLocation(): void
    {
        $locked = $this->createLocation($this->tenantId, ['name' => 'Altes LF', 'active' => 0]);

        $response = $this->post('locations', ['name' => 'Fach 1', 'parent_id' => $locked]);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Unter dem gesperrten Standort „Altes LF“ können keine Unterstandorte angelegt werden.', self::json($response)['message']);
    }

    public function testNamesAreUniquePerParentIgnoringCase(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $lf = $this->createLocation($this->tenantId, ['name' => 'LF 10']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Straßenseite']);
        $this->createLocation($this->tenantId, ['name' => 'Lager', 'kind' => 'room', 'active' => 0]);

        $top = $this->post('locations', ['name' => 'hlf 20', 'kind' => 'room']);
        self::assertError(409, 'name_taken', $top);
        self::assertSame('Es gibt bereits einen Standort „hlf 20“ auf oberster Ebene.', self::json($top)['message']);
        self::assertError(409, 'name_taken', $this->post('locations', ['name' => 'LAGER', 'kind' => 'room']), 'auch gesperrte');

        $sub = $this->post('locations', ['name' => 'Strassenseite', 'parent_id' => $hlf]);
        self::assertError(409, 'name_taken', $sub);
        self::assertSame('Unter „HLF 20“ gibt es bereits einen Standort „Strassenseite“.', self::json($sub)['message']);

        self::assertSame(201, $this->post('locations', ['name' => 'Straßenseite', 'parent_id' => $lf])->statusCode(), 'anderer übergeordneter Standort');
        self::assertSame(201, $this->post('locations', ['name' => 'Straßenseite', 'kind' => 'vehicle'])->statusCode(), 'oberste Ebene');
        self::assertSame(201, $this->post('locations', ['name' => 'HLF 20', 'parent_id' => $lf])->statusCode(), 'Name der oberen Ebene');
    }

    public function testRename(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'hlf 20']);
        $fach1 = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 2']);
        $this->createLocation($this->tenantId, ['name' => 'Fach 2']);

        $response = $this->post(sprintf('locations/%d/rename', $hlf), ['name' => 'HLF 20']);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('HLF 20', self::json($response)['location']['name']);
        self::assertCount(2, self::json($response)['location']['children']);
        self::assertSame(200, $this->post(sprintf('locations/%d/rename', $hlf), ['name' => 'HLF 20'])->statusCode(), 'unverändert');

        $taken = $this->post(sprintf('locations/%d/rename', $fach1), ['name' => 'fach 2']);
        self::assertError(409, 'name_taken', $taken);
        self::assertSame('Unter „HLF 20“ gibt es bereits einen Standort „fach 2“.', self::json($taken)['message']);

        self::assertError(409, 'name_taken', $this->post(sprintf('locations/%d/rename', $hlf), ['name' => 'Fach 2']));
        self::assertError(400, 'invalid_request', $this->post(sprintf('locations/%d/rename', $hlf), ['name' => '']));
        self::assertError(404, 'not_found', $this->post('locations/999999/rename', ['name' => 'Neu']));
        $foreign = $this->createLocation($this->tenant('tuttlingen')->id, ['name' => 'Fremd']);
        self::assertError(404, 'not_found', $this->post(sprintf('locations/%d/rename', $foreign), ['name' => 'Neu']));
        self::assertSame('Fremd', $this->locationValue($foreign, 'name'));
    }

    public function testLockEmptyLocationIsRepeatable(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1', 'active' => 0]);
        $this->createDevice($this->tenantId, $this->typeId, $hlf, ['status' => 'retired']);

        foreach ([1, 2] as $_) {
            $response = $this->post(sprintf('locations/%d/lock', $hlf), []);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertFalse(self::json($response)['location']['active']);
        }
        self::assertSame([], self::json($this->get('locations'))['locations']);
    }

    public function testLockRefusesLocationWithDevicesOrActiveSubLocations(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $fach1 = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 2']);
        $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 3', 'active' => 0]);
        $this->createDevice($this->tenantId, $this->typeId, $hlf);
        $this->createDevice($this->tenantId, $this->typeId, $hlf, ['status' => 'defective']);
        $this->createDevice($this->tenantId, $this->typeId, $hlf, ['status' => 'retired']);
        $this->createDevice($this->tenantId, $this->typeId, $fach1);

        $response = $this->post(sprintf('locations/%d/lock', $hlf), []);

        self::assertError(409, 'rule_violation', $response);
        $json = self::json($response);
        self::assertSame(
            'Der Standort „HLF 20“ ist nicht leer (2 Geräte, 2 aktive Unterstandorte). Bitte zuerst die Geräte umbuchen bzw. die Unterstandorte sperren.',
            $json['message'],
        );
        self::assertSame(['devices' => 2, 'sublocations' => 2], $json['details']);
        self::assertSame(1, (int) $this->locationValue($hlf, 'active'));

        $sub = self::json($this->post(sprintf('locations/%d/lock', $fach1), []));
        self::assertSame(['devices' => 1, 'sublocations' => 0], $sub['details']);
    }

    public function testUnlock(): void
    {
        $hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20', 'active' => 0]);
        $fach = $this->createSubLocation($this->tenantId, $hlf, ['name' => 'Fach 1', 'active' => 0]);

        $refused = $this->post(sprintf('locations/%d/unlock', $fach), []);
        self::assertError(409, 'rule_violation', $refused);
        self::assertSame('Der übergeordnete Standort „HLF 20“ ist gesperrt; bitte zuerst diesen entsperren.', self::json($refused)['message']);
        self::assertSame(0, (int) $this->locationValue($fach, 'active'));

        foreach ([1, 2] as $_) {
            $response = $this->post(sprintf('locations/%d/unlock', $hlf), []);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertTrue(self::json($response)['location']['active']);
            self::assertSame([false], array_column(self::json($response)['location']['children'], 'active'), 'Unterstandort bleibt gesperrt');
        }

        foreach ([1, 2] as $_) {
            $response = $this->post(sprintf('locations/%d/unlock', $fach), []);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertTrue(self::json($response)['location']['active']);
        }
    }

    public function testLockAndUnlockOfForeignOrUnknownLocationIsNotFound(): void
    {
        $foreign = $this->createLocation($this->tenant('tuttlingen')->id, ['active' => 0]);

        foreach ([$foreign, 999999] as $id) {
            self::assertError(404, 'not_found', $this->post(sprintf('locations/%d/lock', $id), []));
            self::assertError(404, 'not_found', $this->post(sprintf('locations/%d/unlock', $id), []));
        }
        self::assertSame(0, (int) $this->locationValue($foreign, 'active'));
    }

    public function testRepositoryRefusesOpenTransaction(): void
    {
        $id = $this->createLocation($this->tenantId);
        $repository = new LocationRepository($this->pdo());

        $this->pdo()->beginTransaction();
        try {
            $repository->lock($this->tenant('immendingen'), $id);
            self::fail('LogicException erwartet.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('eigene Transaktion', $e->getMessage());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    private function get(string $path): JsonResponse
    {
        return $this->send('GET', self::url('immendingen', $path), cookie: $this->admin);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', $path), $body, $this->admin);
    }

    private function locationValue(int $id, string $column): mixed
    {
        $stmt = $this->pdo()->prepare(sprintf('SELECT %s FROM fe_locations WHERE id = ?', $column));
        $stmt->execute([$id]);

        return $stmt->fetchColumn();
    }
}
