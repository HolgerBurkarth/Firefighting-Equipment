<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceReinstatement;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DuplicateDeviceIdentifierException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST devices/{id}/reinstate (Admin): ausgesondertes Gerät mit neuem Status wieder aufnehmen, am
 * bisherigen oder einem anderen aktiven Standort, optional mit neuer Kennung bzw. neuem Gerätetyp
 * (je ein field_changed, danach state_changed, Version einmal erhöht).
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
#[CoversClass(DeviceReinstatement::class)]
#[CoversClass(DuplicateDeviceIdentifierException::class)]
final class DeviceReinstateTest extends DeviceApiTestCase
{
    public function testReinstatesAtPreviousLocation(): void
    {
        $id = $this->retired('MA-01', $this->g1);

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'maintenance', 'location_id' => $this->g1, 'comment' => 'Repariert']);

        self::assertSame(200, $response->statusCode(), $response->body());
        $device = self::json($response)['device'];
        self::assertSame(['maintenance', $this->g1, 2], [$device['status'], $device['location']['id'], $device['version']]);
        $events = $this->events($id);
        self::assertCount(1, $events);
        self::assertSame(['state_changed', $this->adminId, null, null, 'retired', 'maintenance', 'Repariert'], self::state($events[0]));
    }

    public function testReinstatesAtOtherLocation(): void
    {
        $id = $this->retired('MA-01', $this->g1);

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'location_id' => $this->room]);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['ready', 'Lager', 2], [self::json($response)['device']['status'], self::json($response)['device']['location']['path'], self::json($response)['device']['version']]);
        self::assertSame(['state_changed', $this->adminId, $this->g1, $this->room, 'retired', 'ready', null], self::state($this->events($id)[0]));
    }

    public function testIdentifierTakenMeanwhileNeedsNewIdentifier(): void
    {
        $id = $this->retired('MA-01', $this->g1);
        $this->createViaApi('ma-01');
        $before = $this->deviceSnapshot();

        $taken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'identifier' => 'MA-01']);
        self::assertError(409, 'identifier_taken', $taken);
        self::assertSame(
            'Die Kennung „MA-01“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen eine neue Kennung angeben (Feld "identifier").',
            self::json($taken)['message'],
        );
        self::assertSame($before, $this->deviceSnapshot());

        $alsoTaken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'identifier' => 'MA-01 ']);
        self::assertError(409, 'identifier_taken', $alsoTaken);
        $this->createViaApi('MA-02');
        $newTaken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'identifier' => 'ma-02']);
        self::assertError(409, 'identifier_taken', $newTaken);
        self::assertStringStartsWith('Die Kennung „ma-02“ ist bereits an ein anderes Gerät vergeben', self::json($newTaken)['message']);

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'identifier' => 'MA-01a', 'comment' => 'Neu beschriftet']);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['MA-01a', 'ready', 2], [self::json($response)['device']['identifier'], self::json($response)['device']['status'], self::json($response)['device']['version']]);
        $events = $this->events($id);
        self::assertCount(2, $events);
        self::assertSame(['field_changed', 'identifier', 'MA-01', 'MA-01a', 'Neu beschriftet'], [
            $events[0]['event_type'], $events[0]['field'], $events[0]['old_value'], $events[0]['new_value'], $events[0]['comment'],
        ]);
        self::assertSame(['state_changed', $this->adminId, null, null, 'retired', 'ready', 'Neu beschriftet'], self::state($events[1]));
    }

    public function testDeactivatedTypeNeedsNewType(): void
    {
        $old = $this->createDeviceType($this->tenantId, ['name' => 'Altgerät', 'active' => 0]);
        $inactive = $this->createDeviceType($this->tenantId, ['name' => 'Auch alt', 'active' => 0]);
        $id = $this->createDevice($this->tenantId, $old, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready']);
        self::assertError(409, 'rule_violation', $response);
        self::assertSame(
            'Der Gerätetyp „Altgerät“ ist deaktiviert; bitte beim Wiederaufnehmen einen aktiven Gerätetyp angeben (Feld "device_type_id") oder den Typ wieder aktivieren.',
            self::json($response)['message'],
        );
        self::assertError(409, 'rule_violation', $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'device_type_id' => $old]));
        $other = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'device_type_id' => $inactive]);
        self::assertError(409, 'rule_violation', $other);
        self::assertSame('Der Gerätetyp „Auch alt“ ist deaktiviert und kann keinem Gerät zugeordnet werden.', self::json($other)['message']);
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'device_type_id' => $this->otherTypeId]));
        self::assertSame($before, $this->deviceSnapshot());

        $ok = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'device_type_id' => $this->typeId, 'identifier' => 'MA-01']);
        self::assertSame(200, $ok->statusCode(), $ok->body());
        self::assertSame(['Atemschutzmaske', 2], [self::json($ok)['device']['device_type']['name'], self::json($ok)['device']['version']]);
        $events = $this->events($id);
        self::assertSame([['field_changed', 'device_type', 'Altgerät', 'Atemschutzmaske'], ['state_changed', null, null, null]], array_map(
            static fn (array $e): array => [$e['event_type'], $e['field'], $e['old_value'], $e['new_value']],
            $events,
        ));
    }

    public function testLockedLocationIsRejected(): void
    {
        $id = $this->retired('MA-01', $this->room);
        $locked = $this->createLocation($this->tenantId, ['name' => 'Altes Lager', 'kind' => 'room', 'active' => 0]);
        $this->pdo()->prepare('UPDATE fe_locations SET active = 0 WHERE id = ?')->execute([$this->room]);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready']);
        self::assertError(409, 'rule_violation', $response);
        self::assertSame(
            'Der bisherige Standort „Lager“ ist gesperrt; bitte beim Wiederaufnehmen einen aktiven Standort angeben (Feld "location_id").',
            self::json($response)['message'],
        );
        $other = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'location_id' => $locked]);
        self::assertError(409, 'rule_violation', $other);
        self::assertSame('Der Standort „Altes Lager“ ist gesperrt und kann keine Geräte aufnehmen.', self::json($other)['message']);
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'location_id' => $this->otherLocation]));
        self::assertSame($before, $this->deviceSnapshot());

        $ok = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'location_id' => $this->g1]);
        self::assertSame(200, $ok->statusCode(), $ok->body());
    }

    public function testDeviceInServiceIsRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/reinstate', ['version' => 1, 'status' => 'ready']);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame('Das Gerät „MA-01“ ist nicht ausgesondert und kann daher nicht wieder aufgenommen werden.', self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testStaleVersionIsRejected(): void
    {
        $id = $this->retired('MA-01', $this->g1, ['version' => 4]);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 3, 'status' => 'ready']);

        self::assertError(409, 'version_conflict', $response);
        self::assertSame([4, 'retired'], [self::json($response)['details']['device']['version'], self::json($response)['details']['device']['status']]);
        self::assertSame($before, $this->deviceSnapshot());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'ohne Status' => [['version' => 1], '"status"'];
        yield 'ausgesondert' => [['version' => 1, 'status' => 'retired'], 'retire'];
        yield 'Kennung leer' => [['version' => 1, 'status' => 'ready', 'identifier' => null], '"identifier"'];
        yield 'Kennung ungültig' => [['version' => 1, 'status' => 'ready', 'identifier' => 'MA_01'], 'Kennung'];
        yield 'Gerätetyp als Text' => [['version' => 1, 'status' => 'ready', 'device_type_id' => '1'], '"device_type_id"'];
        yield 'andere Stammdaten' => [['version' => 1, 'status' => 'ready', 'note' => 'x'], '"note"'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRejected(array $body, string $message): void
    {
        $id = $this->retired('MA-01', $this->g1);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testUserMayNotReinstateAndForeignDeviceIsNotFound(): void
    {
        $id = $this->retired('MA-01', $this->g1);
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['status' => 'retired']);
        $before = $this->deviceSnapshot();

        self::assertError(403, 'forbidden', $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready'], $this->user));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $foreign . '/reinstate', ['version' => 1, 'status' => 'ready']));
        self::assertSame($before, $this->deviceSnapshot());
    }

    /**
     * Ausgesondertes Gerät per SQL (ohne Ereignisse).
     *
     * @param array<string, scalar|null> $overrides
     */
    private function retired(string $identifier, int $locationId, array $overrides = []): int
    {
        return $this->createDevice($this->tenantId, $this->typeId, $locationId, ['identifier' => $identifier, 'status' => 'retired', ...$overrides]);
    }
}
