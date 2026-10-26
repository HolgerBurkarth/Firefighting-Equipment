<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\DeviceChanges;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceVersionConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST devices/{id}/fields (Admin): nur die angegebenen Felder, je geändertem Feld ein Ereignis
 * field_changed mit gemeinsamem Kommentar, Version einmal erhöht; ohne Unterschied keine Änderung.
 * Veraltete Version 409 version_conflict mit aktuellem Stand, ausgesonderte Geräte 409.
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceJson::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
#[CoversClass(DeviceChanges::class)]
#[CoversClass(DeviceVersionConflictException::class)]
#[CoversClass(DeviceErrors::class)]
final class DeviceFieldsTest extends DeviceApiTestCase
{
    public function testChangesOnlyGivenFieldsWithOneEventEach(): void
    {
        $device = $this->createViaApi('MA-01', ['serial_number' => 'S-1', 'manufacturer' => 'Dräger', 'note' => 'alt']);
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/fields', [
            'version' => 1,
            'identifier' => 'FuG 1',
            'device_type_id' => $radio,
            'serial_number' => null,
            'model' => 'HRT 4000',
            'procured_on' => '2020-01-31',
            'note' => "neu\r\nzweite Zeile",
            'comment' => 'Falsch erfasst',
        ]);

        self::assertSame(200, $response->statusCode(), $response->body());
        $updated = self::json($response)['device'];
        self::assertSame(['FuG 1', 'Funkgerät', null, 'Dräger', 'HRT 4000', '2020-01-31', "neu\nzweite Zeile", 2], [
            $updated['identifier'], $updated['device_type']['name'], $updated['serial_number'], $updated['manufacturer'],
            $updated['model'], $updated['procured_on'], $updated['note'], $updated['version'],
        ]);

        $events = array_slice($this->events($device['id']), 1);
        self::assertSame([
            ['identifier', 'MA-01', 'FuG 1'],
            ['device_type', 'Atemschutzmaske', 'Funkgerät'],
            ['serial_number', 'S-1', null],
            ['model', null, 'HRT 4000'],
            ['procured_on', null, '2020-01-31'],
            ['note', 'alt', "neu\nzweite Zeile"],
        ], array_map(static fn (array $e): array => [$e['field'], $e['old_value'], $e['new_value']], $events));
        foreach ($events as $event) {
            self::assertSame(['field_changed', $this->adminId, 'Falsch erfasst'], [$event['event_type'], (int) $event['user_id'], $event['comment']]);
        }
    }

    public function testUnchangedValuesWriteNothingButVersionIsChecked(): void
    {
        $device = $this->createViaApi('MA-01', ['manufacturer' => 'Dräger']);
        $before = $this->deviceSnapshot();

        $same = $this->api('POST', 'devices/' . $device['id'] . '/fields', [
            'version' => 1, 'identifier' => ' MA-01 ', 'manufacturer' => 'Dräger', 'model' => '', 'device_type_id' => $this->typeId, 'comment' => 'x',
        ]);
        self::assertSame(200, $same->statusCode(), $same->body());
        self::assertSame(1, self::json($same)['device']['version']);
        self::assertSame($before, $this->deviceSnapshot());

        $stale = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 2, 'manufacturer' => 'Dräger']);
        self::assertError(409, 'version_conflict', $stale);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testChangeOfCaseCountsAsChange(): void
    {
        $device = $this->createViaApi('ma-01');

        $response = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'identifier' => 'MA-01']);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(['MA-01', 2], [self::json($response)['device']['identifier'], self::json($response)['device']['version']]);
        self::assertSame(['ma-01', 'MA-01'], [$this->events($device['id'])[1]['old_value'], $this->events($device['id'])[1]['new_value']]);
    }

    public function testStaleVersionReturnsCurrentStateAndWritesNothing(): void
    {
        $device = $this->createViaApi('MA-01');
        self::assertSame(200, $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'model' => 'FPS 7000'])->statusCode());
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'model' => 'Panorama Nova']);

        self::assertError(409, 'version_conflict', $response);
        $json = self::json($response);
        self::assertSame(
            'Das Gerät „MA-01“ wurde inzwischen geändert (aktuelle Version 2). Bitte die Daten neu laden und die Änderung erneut vornehmen.',
            $json['message'],
        );
        self::assertSame(self::json($this->api('GET', 'devices/' . $device['id']))['device'], $json['details']['device']);
        self::assertSame(['FPS 7000', 2], [$json['details']['device']['model'], $json['details']['device']['version']]);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testRetiredDeviceCannotBeChanged(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'model' => 'FPS 7000']);

        self::assertError(409, 'rule_violation', $response);
        self::assertSame(
            'Das Gerät „MA-01“ ist ausgesondert; Stammdaten und Prüfdatum lassen sich erst nach der Wiederaufnahme ändern.',
            self::json($response)['message'],
        );
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testIdentifierTakenByOtherDevice(): void
    {
        $this->createViaApi('MA-01');
        $device = $this->createViaApi('MA-02');
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-03', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        self::assertError(409, 'identifier_taken', $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'identifier' => 'ma-01']));
        self::assertSame($before, $this->deviceSnapshot());

        $reuse = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'identifier' => 'MA-03']);
        self::assertSame(200, $reuse->statusCode(), $reuse->body());
    }

    public function testInactiveOrForeignDeviceTypeIsRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        $inactive = $this->createDeviceType($this->tenantId, ['name' => 'Pager', 'active' => 0]);
        $before = $this->deviceSnapshot();

        self::assertError(409, 'rule_violation', $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'device_type_id' => $inactive]));
        $foreign = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'device_type_id' => $this->otherTypeId]);
        self::assertError(400, 'invalid_request', $foreign);
        self::assertSame('Der Gerätetyp wurde nicht gefunden.', self::json($foreign)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testDeactivatedTypeMayStayOnDevice(): void
    {
        $device = $this->createViaApi('MA-01');
        $this->pdo()->exec('UPDATE fe_device_types SET active = 0 WHERE id = ' . $this->typeId);

        $response = $this->api('POST', 'devices/' . $device['id'] . '/fields', ['version' => 1, 'device_type_id' => $this->typeId, 'model' => 'X']);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertCount(2, $this->events($device['id']));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'Version fehlt' => [['model' => 'X'], 'Das Feld "version" muss eine ganze Zahl'];
        yield 'Version als Text' => [['version' => '1', 'model' => 'X'], 'Das Feld "version" muss eine ganze Zahl'];
        yield 'keine Felder' => [['version' => 1, 'comment' => 'nur Kommentar'], 'Keine Stammdaten zum Ändern angegeben'];
        yield 'Kennung leeren' => [['version' => 1, 'identifier' => null], 'Das Feld "identifier" fehlt'];
        yield 'Typ leeren' => [['version' => 1, 'device_type_id' => null], 'Das Feld "device_type_id" muss eine ganze Zahl'];
        yield 'Status' => [['version' => 1, 'status' => 'defective'], 'Das Feld "status" ist hier nicht vorgesehen'];
        yield 'Standort' => [['version' => 1, 'location_id' => 1], 'Das Feld "location_id" ist hier nicht vorgesehen'];
        yield 'Prüfdatum' => [['version' => 1, 'next_inspection_due' => '2027-01-01'], 'Das Feld "next_inspection_due" ist hier nicht vorgesehen'];
        yield 'Datum ungültig' => [['version' => 1, 'procured_on' => '2027-02-30'], 'Das Beschaffungsdatum muss ein gültiges Datum'];
        yield 'Modell mehrzeilig' => [['version' => 1, 'model' => "a\nb"], 'Das Modell darf keine Steuerzeichen'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRejected(array $body, string $message): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/fields', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testFieldsAreCheckedBeforeDeviceIsLookedUp(): void
    {
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/999999999/fields', ['version' => 1, 'identifier' => 'MA_1']));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/999999999/fields', ['version' => 1, 'model' => 'X']));
    }

    public function testDeviceOfOtherTenantIsNotFound(): void
    {
        $id = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation);
        $before = $this->deviceSnapshot();

        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'model' => 'X']));
        self::assertError(404, 'not_found', $this->api('POST', 'devices/' . $id . '/inspection-due', ['version' => 1, 'next_inspection_due' => null]));
        self::assertError(404, 'not_found', $this->api('GET', 'devices/' . $id));
        self::assertSame($before, $this->deviceSnapshot());
    }
}
