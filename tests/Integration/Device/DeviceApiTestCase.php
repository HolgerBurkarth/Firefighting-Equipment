<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;

/**
 * Basis für Tests der Geräte-API: Stadt immendingen mit Gerätetyp „Atemschutzmaske“, Standort
 * „HLF 20“ mit Unterstandort „G1“, Raum „Lager“, je einem angemeldeten Admin ($admin) und Benutzer
 * ($user). Die zweite Stadt (tuttlingen) hat eigenen Typ und Standort.
 */
abstract class DeviceApiTestCase extends AuthTestCase
{
    use DeviceRows;

    protected int $tenantId;
    protected int $adminId;
    protected int $userId;
    protected int $typeId;
    protected int $hlf;
    protected int $g1;
    protected int $room;
    protected int $otherTenantId;
    protected int $otherTypeId;
    protected int $otherLocation;
    protected string $admin;
    protected string $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->tenant('immendingen')->id;
        $this->typeId = $this->createDeviceType($this->tenantId, ['name' => 'Atemschutzmaske']);
        $this->hlf = $this->createLocation($this->tenantId, ['name' => 'HLF 20']);
        $this->g1 = $this->createSubLocation($this->tenantId, $this->hlf, ['name' => 'G1']);
        $this->room = $this->createLocation($this->tenantId, ['name' => 'Lager', 'kind' => 'room']);
        $this->otherTenantId = $this->tenant('tuttlingen')->id;
        $this->otherTypeId = $this->createDeviceType($this->otherTenantId, ['name' => 'Atemschutzmaske']);
        $this->otherLocation = $this->createLocation($this->otherTenantId, ['name' => 'HLF 20']);

        $this->adminId = $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->userId = $this->account('immendingen', 'user@example.org');
        $this->admin = $this->loginCookie('immendingen', 'admin@example.org');
        $this->user = $this->loginCookie('immendingen', 'user@example.org');
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $path, ?array $body = null, ?string $cookie = null, string $slug = 'immendingen'): JsonResponse
    {
        return $this->send($method, self::url($slug, $path), $body, $cookie ?? $this->admin);
    }

    /**
     * Legt ein Gerät über die API an (Standard: Atemschutzmaske in G1) und liefert dessen JSON.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function createViaApi(string $identifier, array $fields = []): array
    {
        $response = $this->api('POST', 'devices', ['identifier' => $identifier, 'device_type_id' => $this->typeId, 'location_id' => $this->g1, ...$fields]);
        self::assertSame(201, $response->statusCode(), $response->body());

        return self::json($response)['device'];
    }

    /**
     * @return list<array<string, mixed>> Ereignisse eines Geräts in der Reihenfolge des Schreibens
     */
    protected function events(int $deviceId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM fe_device_events WHERE device_id = ? ORDER BY id');
        $stmt->execute([$deviceId]);

        return $stmt->fetchAll();
    }

    /**
     * Ereignis aus events() in Kurzform.
     *
     * @param array<string, mixed> $event
     *
     * @return list<mixed> Art, Benutzer, Standort von/nach, Status von/nach, Kommentar
     */
    protected static function state(array $event): array
    {
        return [
            $event['event_type'],
            (int) $event['user_id'],
            $event['from_location_id'] === null ? null : (int) $event['from_location_id'],
            $event['to_location_id'] === null ? null : (int) $event['to_location_id'],
            $event['from_status'],
            $event['to_status'],
            $event['comment'],
        ];
    }

    /**
     * Stand von Geräten und Historie, um zu prüfen, dass nichts geschrieben wurde.
     */
    protected function deviceSnapshot(): string
    {
        return json_encode([
            $this->pdo()->query('SELECT * FROM fe_devices ORDER BY id')->fetchAll(),
            $this->pdo()->query('SELECT * FROM fe_device_events ORDER BY id')->fetchAll(),
        ], JSON_THROW_ON_ERROR);
    }
}
