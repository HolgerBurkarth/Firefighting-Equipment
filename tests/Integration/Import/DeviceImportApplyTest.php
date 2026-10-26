<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceImportHandler;
use FirefightingEquipment\Device\DeviceEventWriter;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use FirefightingEquipment\Device\Import\ImportOutdatedException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * POST devices/import: erneute Prüfung, nur fehlerfrei und mit dem Kennzeichen der Vorschau, alles
 * in einer Transaktion. Ereignisse wie bei der normalen Bearbeitung, Version je Gerät einmal + 1.
 */
#[CoversClass(DeviceImportHandler::class)]
#[CoversClass(DeviceImport::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(DeviceEventWriter::class)]
#[CoversClass(ImportOutdatedException::class)]
final class DeviceImportApplyTest extends ImportApiTestCase
{
    public function testNewDevicesWithImportedEvent(): void
    {
        $result = $this->import(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'in Wartung', '01.03.2027', 'S1', 'Dräger', 'FPS 7000', '01.05.2024', "Ventil\nprüfen"],
            ['MA-02', 'Atemschutzmaske', 'Lager', '', '', '', '', '', '', ''],
        ]), 'Erstbestand');

        self::assertSame(['summary' => ['rows' => 2, 'new' => 2, 'changed' => 0, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0]], $result);

        $first = $this->deviceRow($this->deviceIdOf('MA-01'));
        self::assertSame(
            [$this->typeId, $this->g1, 'maintenance', 'S1', 'Dräger', 'FPS 7000', '2024-05-01', '2027-03-01', "Ventil\nprüfen", 1],
            [(int) $first['device_type_id'], (int) $first['location_id'], $first['status'], $first['serial_number'], $first['manufacturer'],
                $first['model'], $first['procured_on'], $first['next_inspection_due'], $first['note'], (int) $first['version']],
        );
        self::assertSame([['imported', $this->adminId, null, $this->g1, null, 'maintenance', 'Erstbestand']], array_map(self::state(...), $this->events((int) $first['id'])));

        $second = $this->deviceRow($this->deviceIdOf('MA-02'));
        self::assertSame(['ready', null, null, 1], [$second['status'], $second['serial_number'], $second['next_inspection_due'], (int) $second['version']]);
        self::assertSame([['imported', $this->adminId, null, $this->room, null, 'ready', 'Erstbestand']], array_map(self::state(...), $this->events((int) $second['id'])));

        $event = self::json($this->api('GET', 'devices/' . $first['id'] . '/events'))['events'][0];
        self::assertSame(['imported', 'Importiert'], [$event['type'], $event['type_label']]);
    }

    public function testChangesWriteEventsLikeNormalEditing(): void
    {
        $radio = $this->createDeviceType($this->tenantId, ['name' => 'Funkgerät']);
        $id = $this->createViaApi('MA-01', ['serial_number' => 'S1', 'procured_on' => '2024-05-01', 'next_inspection_due' => '2027-03-01'])['id'];

        $this->import(self::csv([
            ['ma-01', 'Funkgerät', 'Lager', 'defekt', '01.03.2028', '', '', '', '02.05.2024', ''],
        ]), 'Inventur');

        $device = $this->deviceRow($id);
        self::assertSame(
            ['ma-01', $radio, $this->room, 'defective', null, '2024-05-02', '2028-03-01', 2],
            [$device['identifier'], (int) $device['device_type_id'], (int) $device['location_id'], $device['status'], $device['serial_number'],
                $device['procured_on'], $device['next_inspection_due'], (int) $device['version']],
        );

        $events = array_slice($this->events($id), 1);
        self::assertSame(
            [
                ['field_changed', 'identifier', 'MA-01', 'ma-01'],
                ['field_changed', 'device_type', 'Atemschutzmaske', 'Funkgerät'],
                ['field_changed', 'serial_number', 'S1', null],
                ['field_changed', 'procured_on', '2024-05-01', '2024-05-02'],
                ['due_changed', null, '2027-03-01', '2028-03-01'],
                ['state_changed', null, null, null],
            ],
            array_map(static fn (array $e): array => [
                $e['event_type'],
                $e['field'],
                $e['event_type'] === 'due_changed' ? $e['from_due'] : $e['old_value'],
                $e['event_type'] === 'due_changed' ? $e['to_due'] : $e['new_value'],
            ], $events),
        );
        self::assertSame(['state_changed', $this->adminId, $this->g1, $this->room, 'ready', 'defective', 'Inventur'], self::state($events[5]));
        self::assertSame(['Inventur'], array_values(array_unique(array_column($events, 'comment'))));
        self::assertSame([$this->adminId], array_values(array_unique(array_map('intval', array_column($events, 'user_id')))));
    }

    public function testStatusOnlyChangeWritesOneStateEvent(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $this->import(self::csv([['MA-01', 'defekt']], 'Kennung;Status'));

        self::assertSame([['state_changed', $this->adminId, null, null, 'ready', 'defective', null]], array_map(self::state(...), array_slice($this->events($id), 1)));
        self::assertSame(2, (int) $this->deviceRow($id)['version']);
    }

    public function testUnchangedAndSkippedRowsWriteNothing(): void
    {
        $this->createViaApi('MA-01', ['manufacturer' => 'Dräger']);
        $before = $this->deviceSnapshot();

        $result = $this->import(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'einsatzbereit', null, null, 'Dräger', null, null, null],
            ['MA-02', 'Atemschutzmaske', 'Lager', 'ausgesondert', null, null, null, null, null, null],
        ]));

        self::assertSame(['rows' => 2, 'new' => 0, 'changed' => 0, 'unchanged' => 1, 'skipped' => 1, 'errors' => 0, 'warnings' => 0], $result['summary']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testMixedFileIsAppliedTogether(): void
    {
        $existing = $this->createViaApi('MA-01')['id'];

        $result = $this->import(self::csv([['MA-01', 'Atemschutzmaske', 'Lager'], ['MA-02', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'));

        self::assertSame(1, $result['summary']['new']);
        self::assertSame(1, $result['summary']['changed']);
        self::assertSame($this->room, (int) $this->deviceRow($existing)['location_id']);
        self::assertSame($this->room, (int) $this->deviceRow($this->deviceIdOf('MA-02'))['location_id']);
    }

    public function testDeviceChangedSincePreviewIsConflict(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $file = self::csv([['MA-01', 'Lager']], 'Kennung;Standort');
        $token = $this->previewOk($file)['token'];

        self::assertSame(200, $this->api('POST', 'devices/' . $id . '/fields', ['version' => 1, 'model' => 'FPS 7000'], $this->admin)->statusCode());
        $before = $this->deviceSnapshot();

        $response = $this->apply($file, $token);

        self::assertError(409, 'import_outdated', $response);
        self::assertSame(
            'Seit der Vorschau wurden Geräte, Standorte oder Gerätetypen geändert, oder die Datei ist eine andere. Bitte die Vorschau neu erstellen.',
            self::json($response)['message'],
        );
        self::assertSame(['rows' => 1, 'new' => 0, 'changed' => 1, 'unchanged' => 0, 'skipped' => 0, 'errors' => 0, 'warnings' => 0], self::json($response)['details']['summary']);
        self::assertSame($before, $this->deviceSnapshot());

        $this->import($file);
        self::assertSame($this->room, (int) $this->deviceRow($id)['location_id'], 'mit neuer Vorschau klappt es');
    }

    public function testIdentifierTakenSincePreviewIsConflict(): void
    {
        $file = self::csv([['MA-05', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort');
        $token = $this->previewOk($file)['token'];
        $this->createViaApi('ma-05');
        $before = $this->deviceSnapshot();

        self::assertError(409, 'import_outdated', $this->apply($file, $token));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testLocationLockedSincePreviewIsConflictWithErrors(): void
    {
        $this->createViaApi('MA-01');
        $file = self::csv([['MA-01', 'Lager']], 'Kennung;Standort');
        $token = $this->previewOk($file)['token'];
        self::assertSame(200, $this->api('POST', 'locations/' . $this->room . '/lock', [])->statusCode());
        $before = $this->deviceSnapshot();

        $response = $this->apply($file, $token);

        self::assertError(409, 'import_outdated', $response);
        self::assertStringStartsWith('Die Datei enthält inzwischen Fehler', self::json($response)['message']);
        self::assertSame(1, self::json($response)['details']['summary']['errors']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testOtherFileOrWrongTokenIsConflict(): void
    {
        $this->createViaApi('MA-01');
        $file = self::csv([['MA-01', 'Lager']], 'Kennung;Standort');
        $token = $this->previewOk($file)['token'];
        $before = $this->deviceSnapshot();

        self::assertError(409, 'import_outdated', $this->apply(self::csv([['MA-01', 'HLF 20']], 'Kennung;Standort'), $token));
        self::assertError(409, 'import_outdated', $this->apply($file . "\r\n", $token), 'auch eine Leerzeile macht es zu einer anderen Datei');
        self::assertError(409, 'import_outdated', $this->apply($file, str_repeat('0', 64)));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testFileWithErrorsIsNeverApplied(): void
    {
        $file = self::csv([['MA-01', 'Funkgerät', 'Lager']], 'Kennung;Gerätetyp;Standort');
        self::assertNull($this->previewOk($file)['token']);
        $before = $this->deviceSnapshot();

        $response = $this->apply($file, str_repeat('a', 64));

        self::assertError(409, 'import_outdated', $response);
        self::assertSame(1, self::json($response)['details']['summary']['errors']);
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testBodyIsChecked(): void
    {
        $file = base64_encode(self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'));
        $before = $this->deviceSnapshot();

        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => $file]));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => $file, 'token' => 'ABC']));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => $file, 'token' => str_repeat('A', 64)]));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => $file, 'token' => str_repeat('a', 64), 'comment' => str_repeat('x', 501)]));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => $file, 'token' => str_repeat('a', 64), 'status' => 'ready']));
        self::assertError(400, 'invalid_request', $this->api('POST', 'devices/import', ['file' => base64_encode("Kennung;Farbe\r\nMA-01;rot\r\n"), 'token' => str_repeat('a', 64)]));
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testFailureWhileWritingRollsBackEverything(): void
    {
        $this->createViaApi('MA-01');
        $file = DeviceImportFile::parse(self::csv([
            ['MA-01', 'Atemschutzmaske', 'Lager'],
            ['MA-02', 'Atemschutzmaske', 'Lager'],
            ['MA-03', 'Atemschutzmaske', 'Lager'],
        ], 'Kennung;Gerätetyp;Standort'));
        $import = new DeviceImport($this->pdo());
        $token = $import->preview($this->tenant('immendingen'), $file)->token ?? self::fail('Kein Kennzeichen.');
        // Ein Konto einer anderen Stadt verletzt erst beim Schreiben der Ereignisse den Fremdschlüssel,
        // nachdem Geräte schon angelegt und geändert sind.
        $foreignUser = $this->account('tuttlingen', 'fremd@example.org', ['role' => 'admin']);
        $before = $this->deviceSnapshot();

        try {
            $import->apply($this->tenant('immendingen'), $foreignUser, $file, $token, null);
            self::fail('Der Import hätte scheitern müssen.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('fk_fe_device_events_user', $e->getMessage());
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testOpenTransactionIsRejected(): void
    {
        $file = DeviceImportFile::parse(self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'));
        $this->pdo()->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            new DeviceImport($this->pdo())->apply($this->tenant('immendingen'), $this->adminId, $file, str_repeat('a', 64), null);
        } finally {
            $this->pdo()->rollBack();
        }
    }

    public function testOnlyAdminsOfOwnTenant(): void
    {
        $file = self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort');
        $token = $this->previewOk($file)['token'];
        $before = $this->deviceSnapshot();

        self::assertError(403, 'forbidden', $this->preview($file, $this->user));
        self::assertError(403, 'forbidden', $this->apply($file, $token, cookie: $this->user));
        $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $other = $this->loginCookie('tuttlingen', 'admin@example.org');
        self::assertError(401, 'not_authenticated', $this->apply($file, $token, cookie: $other));
        self::assertSame($before, $this->deviceSnapshot());

        // Unter der eigenen Stadt sieht der fremde Admin nur seine Daten: Typ und Standort unbekannt.
        $response = $this->send('POST', self::url('tuttlingen', 'devices/import/preview'), ['file' => base64_encode(self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'))], $other);
        self::assertSame(['device_types' => [], 'locations' => ['Lager']], self::json($response)['unknown']);
    }

    public function testOtherTenantIsUnchanged(): void
    {
        $foreign = $this->createDevice($this->otherTenantId, $this->otherTypeId, $this->otherLocation, ['identifier' => 'MA-01']);
        $before = $this->deviceRow($foreign);

        $this->import(self::csv([['MA-01', 'Atemschutzmaske', 'Lager', 'defekt']], 'Kennung;Gerätetyp;Standort;Status'));

        self::assertSame($before, $this->deviceRow($foreign));
        self::assertSame([], $this->events($foreign));
        self::assertSame('defective', $this->deviceRow($this->deviceIdOf('MA-01'))['status']);
    }
}
