<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * POST devices/{id}/retire (Admin): Status ausgesondert, Ereignis state_changed, Version + 1; die
 * Kennung wird frei. Standort und Prüfdatum bleiben gespeichert.
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
final class DeviceRetireTest extends DeviceApiTestCase
{
    public function testRetiresAndFreesIdentifier(): void
    {
        $device = $this->createViaApi('MA-01', ['status' => 'defective', 'next_inspection_due' => '2027-03-01']);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/retire', ['version' => 1, 'comment' => 'Nicht mehr reparierbar']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $retired = self::json($response)['device'];
        self::assertSame(['retired', 'ausgesondert', 2, $this->g1, '2027-03-01'], [
            $retired['status'], $retired['status_label'], $retired['version'], $retired['location']['id'], $retired['next_inspection_due'],
        ]);
        $events = $this->events($device['id']);
        self::assertCount(2, $events);
        self::assertSame(['state_changed', $this->adminId, null, null, 'defective', 'retired', 'Nicht mehr reparierbar'], self::state($events[1]));

        // Kennung frei (ohne Groß- und Kleinschreibung), das ausgesonderte Gerät ist aus der Liste verschwunden.
        $this->createViaApi('ma-01');
        $list = self::json($this->api('GET', 'devices'))['devices'];
        self::assertSame(['ma-01'], array_column($list, 'identifier'));
    }

    public function testAlreadyRetiredIsRejected(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/retire', ['version' => 1]);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Das Gerät „MA-01“ ist bereits ausgesondert.', self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testStaleVersionIsRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        $this->api('POST', 'devices/' . $device['id'] . '/state', ['version' => 1, 'status' => 'defective']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/retire', ['version' => 1]);

        self::assertError(409, 'version_conflict', $response);
        self::assertSame(2, self::json($response)['details']['device']['version']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testInvalidBodyIsRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/' . $device['id'] . '/retire', []));
        $response = $this->api('POST', 'devices/' . $device['id'] . '/retire', ['version' => 1, 'status' => 'retired']);
        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString('"status"', self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testUserMayNotRetireAndForeignDeviceIsNotFound(): void
    {
        $device = $this->createViaApi('MA-01');
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);
        $before = $this->deviceSnapshot();

        self::assertError(403, 'forbidden', $this->api('POST', 'devices/' . $device['id'] . '/retire', ['version' => 1], $this->user));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $foreign . '/retire', ['version' => 1]));
        self::assertSame($before, $this->deviceSnapshot());
    }
}
