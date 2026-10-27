<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Api\V1\Devices\DeviceListHandler;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DuplicateDeviceScanCodeException;
use FirefightingEquipment\Device\ScanCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Scan-Code über die Geräte-API: anlegen, ändern (field_changed „Scan-Code“), exakt eindeutig je
 * Stadt unter nicht ausgesonderten Geräten (409 scan_code_taken), frei nach dem Aussondern,
 * Wiederaufnehmen, Filter scan_code und Freitextsuche q.
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceJson::class)]
#[CoversClass(DeviceListHandler::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceErrors::class)]
#[CoversClass(DuplicateDeviceScanCodeException::class)]
#[CoversClass(ScanCode::class)]
final class DeviceScanCodeTest extends DeviceApiTestCase
{
    private const string GS1 = "0104006381333931\x1D10AB 7";

    public function testCreateTrimsScannerSuffixAndKeepsContent(): void
    {
        $device = $this->createViaApi('MA-01', ['scan_code' => self::GS1 . "\r\n"]);

        self::assertSame(self::GS1, $device['scan_code']);
        self::assertSame(self::GS1, self::json($this->api('GET', 'devices/' . $device['id']))['device']['scan_code']);
        self::assertSame(
            [['MA-01', self::GS1]],
            array_map(static fn (array $d): array => [$d['identifier'], $d['scan_code']], self::json($this->api('GET', 'devices', cookie: $this->user))['devices']),
        );
        self::assertSame(['created'], array_column($this->events($device['id']), 'event_type'));
    }

    public function testWithoutScanCodeIsNull(): void
    {
        self::assertNull($this->createViaApi('MA-01')['scan_code']);
        self::assertNull($this->createViaApi('MA-02', ['scan_code' => " \t"])['scan_code']);
        self::assertNull($this->createViaApi('MA-03', ['scan_code' => null])['scan_code']);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidScanCodes(): iterable
    {
        yield 'Zeilenumbruch im Inhalt' => ["4006\r\n381", 'keine Steuerzeichen'];
        yield 'Zero-Width-Space' => ["4006\u{200B}381", 'unsichtbare Formatzeichen'];
        yield 'zu lang' => [str_repeat('x', 201), 'höchstens 200 Zeichen'];
        yield 'Zahl statt Text' => [4006381333931, '"scan_code"'];
    }

    #[DataProvider('invalidScanCodes')]
    public function testInvalidScanCodeIsRejected(mixed $scanCode, string $message): void
    {
        $response = $this->api('POST', 'devices', ['identifier' => 'MA-01', 'device_type_id' => $this->typeId, 'location_id' => $this->g1, 'scan_code' => $scanCode]);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_devices')->fetchColumn());
    }

    public function testTakenScanCodeIsConflictAndComparedExactly(): void
    {
        $this->createViaApi('MA-01', ['scan_code' => 'abc' . "\x1D" . '1']);
        $before = $this->deviceSnapshot();

        $taken = $this->api('POST', 'devices', ['identifier' => 'MA-02', 'device_type_id' => $this->typeId, 'location_id' => $this->g1, 'scan_code' => "abc\x1D1\n"]);

        self::assertError(409, 'scan_code_taken', $taken);
        self::assertSame('Der Scan-Code „abc[GS]1“ ist bereits an ein anderes Gerät vergeben.', self::json($taken)['message']);
        self::assertSame($before, $this->deviceSnapshot());
        self::assertSame('ABC' . "\x1D" . '1', $this->createViaApi('MA-02', ['scan_code' => "ABC\x1D1"])['scan_code'], 'Groß- und Kleinschreibung zählt');
        self::assertSame('abc1', $this->createViaApi('MA-03', ['scan_code' => 'abc1'])['scan_code'], 'GS zählt');
    }

    public function testIdentifierAndScanCodeConflictsAreDistinguished(): void
    {
        $this->createViaApi('MA-01', ['scan_code' => 'X1']);

        self::assertError(409, 'identifier_taken', $this->api('POST', 'devices', [
            'identifier' => 'ma-01', 'device_type_id' => $this->typeId, 'location_id' => $this->g1, 'scan_code' => 'X2',
        ]));
        self::assertError(409, 'scan_code_taken', $this->api('POST', 'devices', [
            'identifier' => 'MA-02', 'device_type_id' => $this->typeId, 'location_id' => $this->g1, 'scan_code' => 'X1',
        ]));
        $other = $this->createViaApi('MA-02');
        self::assertError(409, 'scan_code_taken', $this->api('POST', 'devices/' . $other['id'] . '/fields', ['version' => 1, 'scan_code' => 'X1']));
        self::assertError(409, 'identifier_taken', $this->api('POST', 'devices/' . $other['id'] . '/fields', ['version' => 1, 'identifier' => 'MA-01', 'scan_code' => 'X3']));
    }

    public function testFieldsSetChangeAndRemoveScanCodeWithHistory(): void
    {
        $device = $this->createViaApi('MA-01');
        $id = $device['id'];

        $set = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'scan_code' => 'abc', 'comment' => 'Etikett geklebt']);
        self::assertSame(200, $set->statusCode(), $set->body());
        self::assertSame(['abc', 2], [self::json($set)['device']['scan_code'], self::json($set)['device']['version']]);

        $same = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 2, 'scan_code' => "abc\t"]);
        self::assertSame(2, self::json($same)['device']['version'], 'gleicher Wert: keine Änderung');

        $case = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 2, 'scan_code' => 'ABC']);
        self::assertSame(['ABC', 3], [self::json($case)['device']['scan_code'], self::json($case)['device']['version']], 'andere Schreibweise ist eine Änderung');

        $removed = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 3, 'scan_code' => null]);
        self::assertSame([null, 4], [self::json($removed)['device']['scan_code'], self::json($removed)['device']['version']]);
        $empty = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 4, 'scan_code' => '']);
        self::assertSame(4, self::json($empty)['device']['version'], 'leer = null, schon leer');

        $events = self::json($this->api('GET', 'devices/' . $id . '/events', cookie: $this->user))['events'];
        self::assertSame([
            ['Stammdaten geändert', ['name' => 'scan_code', 'label' => 'Scan-Code', 'old' => 'ABC', 'new' => null], null],
            ['Stammdaten geändert', ['name' => 'scan_code', 'label' => 'Scan-Code', 'old' => 'abc', 'new' => 'ABC'], null],
            ['Stammdaten geändert', ['name' => 'scan_code', 'label' => 'Scan-Code', 'old' => null, 'new' => 'abc'], 'Etikett geklebt'],
            ['Angelegt', null, null],
        ], array_map(static fn (array $e): array => [$e['type_label'], $e['field'], $e['comment']], $events));
    }

    public function testFieldsOrderPutsScanCodeAfterIdentifier(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'serial_number' => 'S1', 'scan_code' => 'X', 'identifier' => 'MA-1']);

        self::assertSame(['identifier', 'scan_code', 'serial_number'], array_column(array_slice($this->events($id), 1), 'field'));
    }

    public function testRetiringReleasesScanCode(): void
    {
        $old = $this->createViaApi('MA-01', ['scan_code' => '4006381333931']);
        $retired = $this->api('POST', 'devices/' . $old['id'] . '/retire', ['version' => 1]);
        self::assertSame('4006381333931', self::json($retired)['device']['scan_code'], 'bleibt am ausgesonderten Gerät');

        $new = $this->createViaApi('MA-02', ['scan_code' => '4006381333931']);

        self::assertSame('4006381333931', $new['scan_code']);
        $fields = $this->api('POST', 'devices/' . $old['id'] . '/fields', ['version' => 2, 'scan_code' => 'X']);
        self::assertError(409, 'rule_violation', $fields);
    }

    public function testReinstateWithTakenScanCodeNeedsNewOrNone(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired', 'scan_code' => "A\x1DB"]);
        $this->createViaApi('MA-02', ['scan_code' => "A\x1DB"]);
        $this->createViaApi('MA-03', ['scan_code' => 'C']);
        $before = $this->deviceSnapshot();

        $taken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready']);
        self::assertError(409, 'scan_code_taken', $taken);
        self::assertSame(
            'Der Scan-Code „A[GS]B“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen einen neuen Scan-Code angeben (Feld "scan_code") oder ihn mit null entfernen.',
            self::json($taken)['message'],
        );
        $alsoTaken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'scan_code' => "A\x1DB"]);
        self::assertError(409, 'scan_code_taken', $alsoTaken);
        $newTaken = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'scan_code' => 'C']);
        self::assertError(409, 'scan_code_taken', $newTaken);
        self::assertSame('Der Scan-Code „C“ ist bereits an ein anderes Gerät vergeben.', self::json($newTaken)['message']);
        self::assertSame($before, $this->deviceSnapshot());

        $response = $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready', 'scan_code' => null, 'comment' => 'Etikett ab']);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame([null, 'ready', 2], [self::json($response)['device']['scan_code'], self::json($response)['device']['status'], self::json($response)['device']['version']]);
        $events = $this->events($id);
        self::assertSame(['field_changed', 'scan_code', "A\x1DB", null, 'Etikett ab'], [
            $events[0]['event_type'], $events[0]['field'], $events[0]['old_value'], $events[0]['new_value'], $events[0]['comment'],
        ]);
        self::assertSame(['state_changed', $this->adminId, null, null, 'retired', 'ready', 'Etikett ab'], self::state($events[1]));
    }

    public function testReinstateWithNewScanCodeOrFreeOldOne(): void
    {
        $first = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01', 'status' => 'retired', 'scan_code' => 'A']);
        $second = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-02', 'status' => 'retired', 'scan_code' => 'B']);

        $renamed = $this->api('POST', 'devices/' . $first . '/reinstate', ['version' => 1, 'status' => 'ready', 'scan_code' => 'A2']);
        self::assertSame('A2', self::json($renamed)['device']['scan_code']);
        self::assertSame(['scan_code', null], array_column($this->events($first), 'field'));

        $kept = $this->api('POST', 'devices/' . $second . '/reinstate', ['version' => 1, 'status' => 'ready']);
        self::assertSame('B', self::json($kept)['device']['scan_code']);
        self::assertSame(['state_changed'], array_column($this->events($second), 'event_type'));
    }

    public function testFilterByScanCodeIsExactAndCombinable(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'A', 'scan_code' => "01\x1D10X"]);
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'B', 'scan_code' => 'abc', 'status' => 'defective']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'C', 'scan_code' => 'ABC']);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'D', 'scan_code' => 'abc', 'status' => 'retired']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'E', 'scan_code' => 'abc']);

        self::assertSame(['B'], $this->identifiers('scan_code=abc'));
        self::assertSame(['C'], $this->identifiers('scan_code=ABC'));
        self::assertSame(['A'], $this->identifiers('scan_code=' . rawurlencode("01\x1D10X\r\n")), 'GS im Parameter, CR/LF am Ende entfernt');
        self::assertSame([], $this->identifiers('scan_code=0110X'));
        self::assertSame([], $this->identifiers('scan_code=ab'), 'kein Teiltreffer');
        self::assertSame(['B', 'D'], $this->identifiers('scan_code=abc&include_retired=1'));
        self::assertSame(['B'], $this->identifiers('scan_code=abc&status=defective&location_id=' . $this->room));
        self::assertSame([], $this->identifiers('scan_code=abc&location_id=' . $this->g1));

        self::assertError(400, 'invalid_request', $this->api('GET', 'devices?scan_code=', cookie: $this->user));
        self::assertError(400, 'invalid_request', $this->api('GET', 'devices?scan_code=' . rawurlencode("a\nb"), cookie: $this->user));
    }

    public function testSearchFindsScanCodeIgnoringCase(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'A', 'scan_code' => "XY-4006381333931\x1DZ"]);
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'B', 'scan_code' => 'nichts']);
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'C', 'scan_code' => 'xy-4006381333931']);

        self::assertSame(['A'], $this->identifiers('q=4006381333'));
        self::assertSame(['A'], $this->identifiers('q=xy-4006'));
    }

    public function testSameScanCodeInOtherTenantAndNoAccessAcrossTenants(): void
    {
        $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-01', 'scan_code' => '4006381333931']);

        $device = $this->createViaApi('MA-01', ['scan_code' => '4006381333931']);

        self::assertSame('4006381333931', $device['scan_code']);
        self::assertSame(['MA-01'], $this->identifiers('scan_code=4006381333931'));
        self::assertSame([$device['id']], array_column(self::json($this->api('GET', 'devices?scan_code=4006381333931', cookie: $this->user))['devices'], 'id'));
    }

    public function testVersionConflictShowsScanCode(): void
    {
        $id = $this->createViaApi('MA-01', ['scan_code' => 'A'])['id'];
        $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'scan_code' => 'B']);

        $conflict = $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'scan_code' => 'C']);

        self::assertError(409, 'version_conflict', $conflict);
        self::assertSame('B', self::json($conflict)['details']['device']['scan_code']);
    }

    /**
     * @return list<string> Kennungen der Geräteliste mit diesen Parametern (als Benutzer)
     */
    private function identifiers(string $query): array
    {
        $response = $this->api('GET', 'devices?' . $query, cookie: $this->user);
        self::assertSame(200, $response->statusCode(), $response->body());

        return array_column(self::json($response)['devices'], 'identifier');
    }
}
