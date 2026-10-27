<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Device\DeviceRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * state_changed_at und state_version: Jeder Weg, der Standort oder Status setzt (Anlegen, Umbuchen,
 * Status, Aussondern, Wiederaufnehmen), schreibt beide; Stammdaten, Prüfdatum und Änderungen ohne
 * Wirkung lassen sie unverändert.
 */
#[CoversClass(DeviceRepository::class)]
final class DeviceStateColumnsTest extends DeviceApiTestCase
{
    private const string OLD = '2020-01-01 00:00:00';

    public function testCreateSetsServerTimeAndVersionOne(): void
    {
        $device = $this->createViaApi('MA-01');

        self::assertSame(1, $this->stateRow($device['id'])['state_version']);
        self::assertTrue($this->isRecent($device['id']));
    }

    public function testStateChangesUpdateBoth(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $this->age($id);
        $this->ok('POST', "devices/{$id}/state", ['version' => 1, 'location_id' => $this->room]);
        self::assertSame(['state_version' => 2, 'version' => 2], $this->versions($id));
        self::assertTrue($this->isRecent($id));

        $this->age($id);
        $this->ok('POST', "devices/{$id}/state", ['version' => 2, 'status' => 'defective']);
        self::assertSame(['state_version' => 3, 'version' => 3], $this->versions($id));
        self::assertTrue($this->isRecent($id));

        $this->age($id);
        $this->ok('POST', "devices/{$id}/retire", ['version' => 3]);
        self::assertSame(['state_version' => 4, 'version' => 4], $this->versions($id));
        self::assertTrue($this->isRecent($id));

        $this->age($id);
        $this->ok('POST', "devices/{$id}/reinstate", ['version' => 4, 'status' => 'ready', 'identifier' => 'MA-02']);
        self::assertSame(['state_version' => 5, 'version' => 5], $this->versions($id));
        self::assertTrue($this->isRecent($id));
    }

    public function testOtherChangesKeepBoth(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->age($id);

        $this->ok('POST', "devices/{$id}/fields", ['version' => 1, 'note' => 'Neu']);
        $this->ok('POST', "devices/{$id}/inspection-due", ['version' => 2, 'next_inspection_due' => '2027-01-01']);
        $this->ok('POST', "devices/{$id}/state", ['version' => 3, 'location_id' => $this->g1, 'status' => 'ready']);

        self::assertSame(['state_version' => 1, 'version' => 3], $this->versions($id));
        self::assertSame(self::OLD, $this->stateRow($id)['state_changed_at']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ok(string $method, string $path, array $body): void
    {
        $response = $this->api($method, $path, $body);
        self::assertSame(200, $response->statusCode(), $response->body());
    }

    private function age(int $id): void
    {
        $this->pdo()->prepare('UPDATE fe_devices SET state_changed_at = ? WHERE id = ?')->execute([self::OLD, $id]);
    }

    private function isRecent(int $id): bool
    {
        $stmt = $this->pdo()->prepare('SELECT ABS(TIMESTAMPDIFF(SECOND, state_changed_at, UTC_TIMESTAMP())) < 60 FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * @return array{state_version: int, version: int}
     */
    private function versions(int $id): array
    {
        $row = $this->stateRow($id);

        return ['state_version' => $row['state_version'], 'version' => $row['version']];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateRow(int $id): array
    {
        $stmt = $this->pdo()->prepare('SELECT state_changed_at, state_version, version FROM fe_devices WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: self::fail('Gerät fehlt.');
    }
}
