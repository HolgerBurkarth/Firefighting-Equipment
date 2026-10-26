<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\NewDevice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST devices (Admin): Normalisierung der Kennung, Pflicht- und optionale Felder, Ereignis created,
 * Kennung eindeutig je Stadt unter nicht ausgesonderten Geräten (ohne Groß- und Kleinschreibung,
 * ß ≠ ss), Ziel-Standort und Gerätetyp aktiv und aus derselben Stadt.
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceJson::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
#[CoversClass(NewDevice::class)]
#[CoversClass(DeviceErrors::class)]
final class DeviceCreateTest extends DeviceApiTestCase
{
    public function testCreatesDeviceWithAllFieldsAndEvent(): void
    {
        $response = $this->api('POST', 'devices', [
            'identifier' => '  MA   01 ',
            'device_type_id' => $this->typeId,
            'location_id' => $this->g1,
            'status' => 'maintenance',
            'serial_number' => ' ARFK-1234 ',
            'manufacturer' => 'Dräger',
            'model' => 'FPS 7000',
            'procured_on' => '2021-04-15',
            'next_inspection_due' => '2027-03-01',
            'note' => "Zeile 1\r\nZeile 2",
            'comment' => ' Neu beschafft ',
        ]);

        self::assertSame(201, $response->statusCode(), $response->body());
        $device = self::json($response)['device'];
        self::assertSame(
            ['id', 'identifier', 'device_type', 'location', 'status', 'status_label', 'next_inspection_due', 'version',
                'serial_number', 'manufacturer', 'model', 'procured_on', 'note', 'created_at', 'updated_at'],
            array_keys($device),
        );
        self::assertSame('MA 01', $device['identifier']);
        self::assertSame(['id' => $this->typeId, 'name' => 'Atemschutzmaske'], $device['device_type']);
        self::assertSame(['id' => $this->g1, 'name' => 'G1', 'path' => 'HLF 20 / G1'], $device['location']);
        self::assertSame(['maintenance', 'in Wartung', '2027-03-01', 1], [
            $device['status'], $device['status_label'], $device['next_inspection_due'], $device['version'],
        ]);
        self::assertSame(['ARFK-1234', 'Dräger', 'FPS 7000', '2021-04-15', "Zeile 1\nZeile 2"], [
            $device['serial_number'], $device['manufacturer'], $device['model'], $device['procured_on'], $device['note'],
        ]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $device['created_at']);

        $events = $this->events($device['id']);
        self::assertCount(1, $events);
        self::assertSame(
            ['created', $this->adminId, null, $this->g1, null, 'maintenance', null, null, null, 'Neu beschafft'],
            [
                $events[0]['event_type'], (int) $events[0]['user_id'], $events[0]['from_location_id'], (int) $events[0]['to_location_id'],
                $events[0]['from_status'], $events[0]['to_status'], $events[0]['from_due'], $events[0]['field'], $events[0]['new_value'],
                $events[0]['comment'],
            ],
        );
    }

    public function testMinimalDeviceIsReadyWithEmptyOptionalFields(): void
    {
        $device = $this->createViaApi('MA-01', ['serial_number' => '  ', 'note' => '', 'comment' => '', 'location_id' => $this->hlf]);

        self::assertSame(['ready', 'einsatzbereit'], [$device['status'], $device['status_label']]);
        self::assertSame(['id' => $this->hlf, 'name' => 'HLF 20', 'path' => 'HLF 20'], $device['location']);
        foreach (['serial_number', 'manufacturer', 'model', 'procured_on', 'next_inspection_due', 'note'] as $field) {
            self::assertNull($device[$field], $field);
        }
        $row = $this->pdo()->query('SELECT serial_number, note FROM fe_devices WHERE id = ' . $device['id'])->fetch();
        self::assertSame(['serial_number' => null, 'note' => null], $row);
        self::assertNull($this->events($device['id'])[0]['comment']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'Kennung fehlt' => [['identifier' => null], 'Das Feld "identifier" fehlt'];
        yield 'Kennung ungültig' => [['identifier' => 'MA_01'], 'nur Buchstaben, Ziffern'];
        yield 'Kennung zu lang' => [['identifier' => str_repeat('A', 31)], 'höchstens 30 Zeichen'];
        yield 'Typ fehlt' => [['device_type_id' => null], 'Das Feld "device_type_id" muss eine ganze Zahl'];
        yield 'Typ als Text' => [['device_type_id' => '1'], 'Das Feld "device_type_id" muss eine ganze Zahl'];
        yield 'Standort fehlt' => [['location_id' => null], 'Das Feld "location_id" muss eine ganze Zahl'];
        yield 'Status ausgesondert' => [['status' => 'retired'], 'Ein neues Gerät kann nicht ausgesondert sein.'];
        yield 'Status unbekannt' => [['status' => 'kaputt'], 'Das Feld "status" muss einer der Werte'];
        yield 'Status großgeschrieben' => [['status' => 'READY'], 'Das Feld "status" muss einer der Werte'];
        yield 'Datum deutsch' => [['procured_on' => '15.04.2021'], 'Das Beschaffungsdatum muss ein gültiges Datum im Format JJJJ-MM-TT sein.'];
        yield 'Datum Tippfehler' => [['next_inspection_due' => '0202-03-01'], 'Das Datum der nächsten Prüfung muss zwischen 1950-01-01 und 2099-12-31 liegen.'];
        yield 'Datum als Zahl' => [['procured_on' => 20210415], 'Das Feld "procured_on" ist kein Text.'];
        yield 'Notiz zu lang' => [['note' => str_repeat('x', 1001)], 'höchstens 1000 Zeichen'];
        yield 'Kommentar mehrzeilig' => [['comment' => "a\nb"], 'Der Kommentar darf keine Steuerzeichen'];
        yield 'unbekanntes Feld' => [['serial' => 'X'], 'Das Feld "serial" ist hier nicht vorgesehen'];
        yield 'Typ unbekannt' => [['device_type_id' => 999_999_999], 'Der Gerätetyp wurde nicht gefunden.'];
        yield 'Standort unbekannt' => [['location_id' => 999_999_999], 'Der Standort wurde nicht gefunden.'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRejectedWithoutWriting(array $override, string $message): void
    {
        $body = array_filter(
            ['identifier' => 'MA-01', 'device_type_id' => $this->typeId, 'location_id' => $this->g1, ...$override],
            static fn (mixed $v): bool => $v !== null,
        );
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testTypeAndLocationOfOtherTenantAreUnknown(): void
    {
        $before = $this->deviceSnapshot();

        $type = $this->api('POST', 'devices', ['identifier' => 'MA-01', 'device_type_id' => $this->otherTypeId, 'location_id' => $this->g1]);
        $location = $this->api('POST', 'devices', ['identifier' => 'MA-01', 'device_type_id' => $this->typeId, 'location_id' => $this->otherLocation]);

        self::assertError(400, 'invalid_request', $type);
        self::assertSame('Der Gerätetyp wurde nicht gefunden.', self::json($type)['message']);
        self::assertError(400, 'invalid_request', $location);
        self::assertSame('Der Standort wurde nicht gefunden.', self::json($location)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testLockedLocationAndInactiveTypeAreRejected(): void
    {
        $locked = $this->createLocation($this->tenantId, ['name' => 'Altes LF', 'active' => 0]);
        $inactive = $this->createDeviceType($this->tenantId, ['name' => 'Pager', 'active' => 0]);
        $before = $this->deviceSnapshot();

        $location = $this->api('POST', 'devices', ['identifier' => 'MA-01', 'device_type_id' => $this->typeId, 'location_id' => $locked]);
        $type = $this->api('POST', 'devices', ['identifier' => 'MA-01', 'device_type_id' => $inactive, 'location_id' => $this->g1]);

        self::assertError(409, 'rule_violation', $location);
        self::assertSame('Der Standort „Altes LF“ ist gesperrt und kann keine Geräte aufnehmen.', self::json($location)['message']);
        self::assertError(409, 'rule_violation', $type);
        self::assertSame('Der Gerätetyp „Pager“ ist deaktiviert und kann keinem Gerät zugeordnet werden.', self::json($type)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testIdentifierIsUniqueIgnoringCaseAcrossTypes(): void
    {
        $this->createViaApi('MA-01');
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices', ['identifier' => 'ma-01', 'device_type_id' => $radio, 'location_id' => $this->room]);

        self::assertError(409, 'identifier_taken', $response);
        self::assertSame(
            'Die Kennung „ma-01“ ist bereits an ein anderes Gerät vergeben (Groß- und Kleinschreibung zählt dabei nicht).',
            self::json($response)['message'],
        );
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testIdentifierComparisonKeepsUmlautsAndSharpS(): void
    {
        $this->createViaApi('STRASSE-1');
        $this->createViaApi('Café-1');

        $this->createViaApi('STRAßE-1');
        $this->createViaApi('Cafe-1');
        self::assertError(409, 'identifier_taken', $this->api('POST', 'devices', [
            'identifier' => 'STRAẞE-1', 'device_type_id' => $this->typeId, 'location_id' => $this->g1,
        ]));
        self::assertError(409, 'identifier_taken', $this->api('POST', 'devices', [
            'identifier' => 'CAFÉ-1', 'device_type_id' => $this->typeId, 'location_id' => $this->g1,
        ]));
    }

    public function testIdentifierCanBeReusedAfterRetirement(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired']);

        $device = $this->createViaApi('MA-01');

        self::assertSame('MA-01', $device['identifier']);
    }

    public function testSameIdentifierInOtherTenantIsAllowed(): void
    {
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-01']);

        self::assertSame('MA-01', $this->createViaApi('MA-01')['identifier']);
    }

    public function testDeviceTypeAndLocationCountsIncludeNewDevice(): void
    {
        $this->createViaApi('MA-01');

        $types = self::json($this->api('GET', 'device-types'))['device_types'];
        self::assertSame(1, array_column($types, 'device_count', 'name')['Atemschutzmaske']);
        $location = self::json($this->api('GET', 'locations/' . $this->hlf))['location'];
        self::assertSame([0, 1], [$location['device_count'], $location['device_count_total']]);
    }
}
