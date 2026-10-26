<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\Import\DeviceImport;
use FirefightingEquipment\Device\Import\DeviceImportFile;
use FirefightingEquipment\Device\Import\DeviceImportPlanner;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Sperren beim Übernehmen: Eine zweite Verbindung hält jeweils eine Sperre; der Import wartet (hier
 * nach 1 s Lock-Wait-Timeout) statt an ihr vorbei zu prüfen oder zu schreiben, und schreibt nichts.
 */
#[CoversClass(DeviceImport::class)]
#[CoversClass(DeviceImportPlanner::class)]
#[CoversClass(DeviceRepository::class)]
final class DeviceImportLockingTest extends ImportApiTestCase
{
    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    public function testWaitsForDeviceBeingChanged(): void
    {
        $id = $this->createViaApi('MA-01')['id'];

        $this->whileConcurrent('SELECT id FROM fe_devices WHERE id = ? FOR UPDATE', [$id], self::csv([['MA-01', 'defekt']], 'Kennung;Status'));
    }

    public function testWaitsForTargetLocationBeingLocked(): void
    {
        $this->createViaApi('MA-01');

        $this->whileConcurrent('SELECT id FROM fe_locations WHERE id = ? FOR UPDATE', [$this->room], self::csv([['MA-01', 'Lager']], 'Kennung;Standort'));
    }

    public function testWaitsForDeviceTypeBeingChanged(): void
    {
        $this->whileConcurrent(
            'SELECT id FROM fe_device_types WHERE id = ? FOR UPDATE',
            [$this->typeId],
            self::csv([['MA-01', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'),
        );
    }

    public function testNewIdentifierWaitsForConcurrentInsert(): void
    {
        $this->whileConcurrent(
            "INSERT INTO fe_devices (tenant_id, identifier, device_type_id, location_id, status) VALUES (?, 'ma-05', ?, ?, 'ready')",
            [$this->tenantId, $this->typeId, $this->room],
            self::csv([['MA-05', 'Atemschutzmaske', 'Lager']], 'Kennung;Gerätetyp;Standort'),
        );
    }

    /**
     * Eine zweite Verbindung führt $sql in einer offenen Transaktion aus, dann wird $csv (fehlerfrei
     * geprüft) übernommen: Das muss in einen Lock-Wait-Timeout laufen, ohne etwas zu schreiben.
     *
     * @param list<int> $params
     */
    private function whileConcurrent(string $sql, array $params, string $csv): void
    {
        $file = DeviceImportFile::parse($csv);
        $import = new DeviceImport($this->pdo());
        $token = $import->preview($this->tenant('immendingen'), $file)->token ?? self::fail('Kein Kennzeichen.');
        $before = $this->deviceSnapshot();

        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->prepare($sql)->execute($params);
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $import->apply($this->tenant('immendingen'), $this->adminId, $file, $token, null);
            self::fail('Der Import hat nicht auf die Sperre der zweiten Verbindung gewartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
        } finally {
            $concurrent->rollBack();
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame($before, $this->deviceSnapshot());
    }
}
