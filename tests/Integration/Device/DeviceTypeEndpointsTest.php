<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeActionHandler;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeJson;
use FirefightingEquipment\Api\V1\DeviceTypes\DeviceTypeListHandler;
use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Gerätetypen über die ganze Anwendung: Liste (angemeldet), Anlegen, Umbenennen, Deaktivieren und
 * Aktivieren (Admin). Rechte aller Routen: AdminRoutesAccessTest und TenantReadRoutesAccessTest.
 */
#[CoversClass(DeviceTypeListHandler::class)]
#[CoversClass(DeviceTypeActionHandler::class)]
#[CoversClass(DeviceTypeJson::class)]
#[CoversClass(DeviceTypeRepository::class)]
#[CoversClass(DeviceErrors::class)]
final class DeviceTypeEndpointsTest extends AuthTestCase
{
    use DeviceRows;

    private int $tenantId;
    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->tenant('immendingen')->id;
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->admin = $this->loginCookie('immendingen', 'admin@example.org');
    }

    public function testListShowsActiveTypesByNameWithCountOfNotRetiredDevices(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $this->createDeviceType($this->tenantId, ['name' => 'Atemschutzmaske']);
        $old = $this->createDeviceType($this->tenantId, ['name' => 'Alter Typ', 'active' => 0]);
        $location = $this->createLocation($this->tenantId);
        $this->createDevice($this->tenantId, $radio, $location);
        $this->createDevice($this->tenantId, $radio, $location, ['status' => 'defective']);
        $this->createDevice($this->tenantId, $radio, $location, ['status' => 'retired']);
        $this->createDevice($this->tenantId, $old, $location);
        $other = $this->tenant('tuttlingen')->id;
        $this->createDevice($other, $this->createDeviceType($other, ['name' => 'Funkgerät']), $this->createLocation($other));
        $this->account('immendingen', 'user@example.org');
        $user = $this->loginCookie('immendingen', 'user@example.org');

        $response = $this->send('GET', self::url('immendingen', 'device-types'), cookie: $user);

        self::assertSame(200, $response->statusCode(), $response->body());
        $types = self::json($response)['device_types'];
        self::assertSame(['Atemschutzmaske', 'Funkgerät'], array_column($types, 'name'));
        self::assertSame([0, 2], array_column($types, 'device_count'));
        self::assertSame(['id', 'name', 'active', 'device_count', 'created_at', 'updated_at'], array_keys($types[1]));
        self::assertSame($radio, $types[1]['id']);
        self::assertTrue($types[1]['active']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $types[1]['created_at']);

        $all = self::json($this->send('GET', self::url('immendingen', 'device-types?include_inactive=1'), cookie: $user))['device_types'];
        self::assertSame(['Alter Typ', 'Atemschutzmaske', 'Funkgerät'], array_column($all, 'name'));
        self::assertSame([false, true, true], array_column($all, 'active'));
        self::assertSame(1, $all[0]['device_count']);

        $hidden = self::json($this->send('GET', self::url('immendingen', 'device-types?include_inactive=0'), cookie: $user))['device_types'];
        self::assertCount(2, $hidden);
        self::assertError(400, 'invalid_request', $this->send('GET', self::url('immendingen', 'device-types?include_inactive=ja'), cookie: $user));
    }

    public function testCreateTrimsName(): void
    {
        $response = $this->post('device-types', ['name' => '  Wärmebildkamera ']);

        self::assertSame(201, $response->statusCode(), $response->body());
        $type = self::json($response)['device_type'];
        self::assertSame('Wärmebildkamera', $type['name']);
        self::assertTrue($type['active']);
        self::assertSame(0, $type['device_count']);
        $stmt = $this->pdo()->prepare('SELECT tenant_id, name FROM fe_device_types WHERE id = ?');
        $stmt->execute([$type['id']]);
        self::assertEquals(['tenant_id' => $this->tenantId, 'name' => 'Wärmebildkamera'], $stmt->fetch());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function takenNames(): iterable
    {
        yield 'gleich' => ['Straßenkarte'];
        yield 'Kleinschreibung' => ['straßenkarte'];
        yield 'Großschreibung' => ['STRASSENKARTE'];
        yield 'ss statt ß' => ['Strassenkarte'];
    }

    #[DataProvider('takenNames')]
    public function testNameIsUniquePerTenantIgnoringCase(string $name): void
    {
        $this->createDeviceType($this->tenantId, ['name' => 'Straßenkarte', 'active' => 0]);

        $response = $this->post('device-types', ['name' => $name]);

        self::assertError(409, 'name_taken', $response);
        self::assertSame(sprintf('Es gibt bereits einen Gerätetyp „%s“.', $name), self::json($response)['message']);
        self::assertSame(1, $this->countRows('fe_device_types'));
    }

    public function testSameNameInOtherTenantIsAllowed(): void
    {
        $this->createDeviceType($this->tenant('tuttlingen')->id, ['name' => 'Funkgerät']);

        self::assertSame(201, $this->post('device-types', ['name' => 'Funkgerät'])->statusCode());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Name' => [[], 'Das Feld "name" fehlt oder ist kein Text.'];
        yield 'Zahl' => [['name' => 5], 'Das Feld "name" fehlt oder ist kein Text.'];
        yield 'leer' => [['name' => '  '], 'Der Name darf nicht leer sein.'];
        yield 'zu lang' => [['name' => str_repeat('x', 51)], 'Der Name darf höchstens 50 Zeichen lang sein (angegeben: 51).'];
        yield 'Zeilenumbruch' => [['name' => "Funk\ngerät"], 'Der Name darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidNameIsRejected(array $body, string $message): void
    {
        $id = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);

        foreach ([$this->post('device-types', $body), $this->post(sprintf('device-types/%d/rename', $id), $body)] as $response) {
            self::assertError(400, 'invalid_request', $response);
            self::assertSame($message, self::json($response)['message']);
        }
        // 400 vor 404: auch bei unbekannter ID
        self::assertError(400, 'invalid_request', $this->post('device-types/999999/rename', $body));
        self::assertSame(1, $this->countRows('fe_device_types'));
    }

    public function testRename(): void
    {
        $id = $this->createDeviceType($this->tenantId, ['name' => 'funkgerät']);
        $this->createDeviceType($this->tenantId, ['name' => 'Atemschutzmaske']);

        $response = $this->post(sprintf('device-types/%d/rename', $id), ['name' => 'Funkgerät']);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('Funkgerät', self::json($response)['device_type']['name']);

        self::assertSame(200, $this->post(sprintf('device-types/%d/rename', $id), ['name' => 'Funkgerät'])->statusCode(), 'unverändert');
        self::assertSame(200, $this->post(sprintf('device-types/%d/rename', $id), ['name' => 'Handfunkgerät'])->statusCode());

        $taken = $this->post(sprintf('device-types/%d/rename', $id), ['name' => 'ATEMSCHUTZMASKE']);
        self::assertError(409, 'name_taken', $taken);
        self::assertSame('Handfunkgerät', $this->typeName($id));
    }

    public function testForeignOrUnknownTypeIsNotFound(): void
    {
        $foreign = $this->createDeviceType($this->tenant('tuttlingen')->id, ['name' => 'Funkgerät']);

        foreach ([$foreign, 999999] as $id) {
            self::assertError(404, 'not_found', $this->post(sprintf('device-types/%d/rename', $id), ['name' => 'Neu']));
            self::assertError(404, 'not_found', $this->post(sprintf('device-types/%d/deactivate', $id), []));
            self::assertError(404, 'not_found', $this->post(sprintf('device-types/%d/activate', $id), []));
        }
        self::assertSame('Funkgerät', $this->typeName($foreign));
    }

    public function testDeactivateAndActivateAreRepeatableAndDevicesKeepTheirType(): void
    {
        $id = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $device = $this->createDevice($this->tenantId, $id, $this->createLocation($this->tenantId));

        foreach ([1, 2] as $_) {
            $response = $this->post(sprintf('device-types/%d/deactivate', $id), []);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertFalse(self::json($response)['device_type']['active']);
            self::assertSame(1, self::json($response)['device_type']['device_count']);
        }
        $list = self::json($this->send('GET', self::url('immendingen', 'device-types'), cookie: $this->admin))['device_types'];
        self::assertSame([], $list);
        $stmt = $this->pdo()->prepare('SELECT device_type_id FROM fe_devices WHERE id = ?');
        $stmt->execute([$device]);
        self::assertSame($id, (int) $stmt->fetchColumn());

        foreach ([1, 2] as $_) {
            $response = $this->post(sprintf('device-types/%d/activate', $id), []);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertTrue(self::json($response)['device_type']['active']);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $path, array $body): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', $path), $body, $this->admin);
    }

    private function typeName(int $id): string
    {
        $stmt = $this->pdo()->prepare('SELECT name FROM fe_device_types WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }
}
