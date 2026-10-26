<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Api\V1\Devices\DeviceActionHandler;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\DeviceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST devices/{id}/inspection-due (Admin): Prüfdatum setzen oder entfernen (null), Ereignis
 * due_changed mit optionalem Kommentar, Version erhöht; ohne Unterschied keine Änderung.
 */
#[CoversClass(DeviceActionHandler::class)]
#[CoversClass(DeviceFields::class)]
#[CoversClass(DeviceRepository::class)]
#[CoversClass(DeviceEventWriter::class)]
final class DeviceInspectionDueTest extends DeviceApiTestCase
{
    public function testSetChangeAndRemoveDueDate(): void
    {
        $device = $this->createViaApi('MA-01');
        $path = 'devices/' . $device['id'] . '/inspection-due';

        $set = $this->api('POST', $path, ['version' => 1, 'next_inspection_due' => '2027-03-01', 'comment' => 'Erstprüfung']);
        $change = $this->api('POST', $path, ['version' => 2, 'next_inspection_due' => '2028-03-01']);
        $remove = $this->api('POST', $path, ['version' => 3, 'next_inspection_due' => null]);

        self::assertSame(['2027-03-01', 2], [self::json($set)['device']['next_inspection_due'], self::json($set)['device']['version']]);
        self::assertSame(['2028-03-01', 3], [self::json($change)['device']['next_inspection_due'], self::json($change)['device']['version']]);
        self::assertSame([null, 4], [self::json($remove)['device']['next_inspection_due'], self::json($remove)['device']['version']]);
        self::assertSame(
            [
                ['due_changed', null, '2027-03-01', 'Erstprüfung'],
                ['due_changed', '2027-03-01', '2028-03-01', null],
                ['due_changed', '2028-03-01', null, null],
            ],
            array_map(
                static fn (array $e): array => [$e['event_type'], $e['from_due'], $e['to_due'], $e['comment']],
                array_slice($this->events($device['id']), 1),
            ),
        );
    }

    public function testSameDateWritesNothing(): void
    {
        $device = $this->createViaApi('MA-01', ['next_inspection_due' => '2027-03-01']);
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/inspection-due', ['version' => 1, 'next_inspection_due' => '2027-03-01']);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame(1, self::json($response)['device']['version']);
        self::assertSame($before, $this->deviceSnapshot());
        self::assertError(409, 'version_conflict', $this->api('POST', 'devices/' . $device['id'] . '/inspection-due', ['version' => 5, 'next_inspection_due' => '2027-03-01']));
    }

    public function testStaleVersionAndRetiredDeviceAreRejected(): void
    {
        $device = $this->createViaApi('MA-01');
        $retired = $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-02', 'status' => 'retired']);
        $before = $this->deviceSnapshot();

        $stale = $this->api('POST', 'devices/' . $device['id'] . '/inspection-due', ['version' => 2, 'next_inspection_due' => '2027-03-01']);
        self::assertError(409, 'version_conflict', $stale);
        self::assertSame(1, self::json($stale)['details']['device']['version']);
        self::assertError(409, 'rule_violation', $this->api('POST', 'devices/' . $retired . '/inspection-due', ['version' => 1, 'next_inspection_due' => '2027-03-01']));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testRepositoryRefusesOpenTransaction(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->g1);
        $repository = new DeviceRepository($this->pdo());

        $this->pdo()->beginTransaction();
        try {
            $repository->setInspectionDue($this->tenant('immendingen'), $this->adminId, $id, 1, null, null);
            self::fail('LogicException erwartet.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('eigene Transaktion', $e->getMessage());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'Datum fehlt' => [['version' => 1], 'Das Feld "next_inspection_due" fehlt'];
        yield 'Version fehlt' => [['next_inspection_due' => '2027-03-01'], 'Das Feld "version" muss eine ganze Zahl'];
        yield 'Version 0' => [['version' => 0, 'next_inspection_due' => null], 'Das Feld "version" muss eine ganze Zahl'];
        yield 'Format' => [['version' => 1, 'next_inspection_due' => '1.3.2027'], 'Das Datum der nächsten Prüfung muss ein gültiges Datum'];
        yield 'Grenze' => [['version' => 1, 'next_inspection_due' => '2100-01-01'], 'muss zwischen 1950-01-01 und 2099-12-31 liegen'];
        yield 'anderes Feld' => [['version' => 1, 'next_inspection_due' => null, 'model' => 'X'], 'Das Feld "model" ist hier nicht vorgesehen'];
        yield 'Kommentar zu lang' => [['version' => 1, 'next_inspection_due' => null, 'comment' => str_repeat('x', 501)], 'höchstens 500 Zeichen'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testInvalidBodyIsRejected(array $body, string $message): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        $response = $this->api('POST', 'devices/' . $device['id'] . '/inspection-due', $body);

        self::assertError(400, 'invalid_request', $response);
        self::assertStringContainsString($message, self::json($response)['message']);
        self::assertSame($before, $this->deviceSnapshot());
    }
}
