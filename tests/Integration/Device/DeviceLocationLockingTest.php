<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Device\DeviceReinstatement;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Gleichzeitiges Sperren eines Standorts und Umbuchen bzw. Wiederaufnehmen dorthin: Wer den Standort
 * als Ziel benutzt, liest ihn mit LOCK IN SHARE MODE; lock() sperrt ihn mit FOR UPDATE und zählt erst
 * danach. Eine zweite Verbindung hält jeweils die Sperre der Gegenseite; die andere Seite wartet
 * (hier nach 1 s Lock-Wait-Timeout) statt an der Sperre vorbei zu schreiben. Nacheinander gewinnt
 * jeweils die erste Änderung.
 */
#[CoversClass(DeviceRepository::class)]
#[CoversClass(LocationRepository::class)]
final class DeviceLocationLockingTest extends DeviceApiTestCase
{
    private const int ER_LOCK_WAIT_TIMEOUT = 1205;

    public function testMoveWaitsWhileTargetIsBeingLocked(): void
    {
        $device = $this->createViaApi('MA-01');
        $before = $this->deviceSnapshot();

        $this->whileConcurrent('FOR UPDATE', $this->room, function () use ($device): void {
            new DeviceRepository($this->pdo())->changeState(
                $this->tenant('immendingen'),
                $this->adminId,
                $device['id'],
                1,
                new DeviceStateChange($this->room, null),
                null,
            );
        });

        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testReinstateWaitsWhileTargetIsBeingLocked(): void
    {
        $id = $this->createDevice($this->tenantId, $this->typeId, $this->room, ['status' => 'retired']);
        $before = $this->deviceSnapshot();

        $this->whileConcurrent('FOR UPDATE', $this->room, function () use ($id): void {
            new DeviceRepository($this->pdo())->reinstate($this->tenant('immendingen'), $this->adminId, $id, 1, new DeviceReinstatement(DeviceStatus::Ready), null);
        });

        self::assertSame($before, $this->deviceSnapshot());
    }

    public function testLockWaitsWhileDeviceIsMovedIn(): void
    {
        $this->createViaApi('MA-01');
        $before = $this->pdo()->query('SELECT * FROM fe_locations ORDER BY id')->fetchAll();

        $this->whileConcurrent('LOCK IN SHARE MODE', $this->room, function (): void {
            new LocationRepository($this->pdo())->lock($this->tenant('immendingen'), $this->room);
        });

        self::assertSame($before, $this->pdo()->query('SELECT * FROM fe_locations ORDER BY id')->fetchAll());
    }

    public function testFirstChangeWinsWhenSequential(): void
    {
        $first = $this->createViaApi('MA-01');
        $second = $this->createViaApi('MA-02');

        self::assertSame(200, $this->api('POST', 'locations/' . $this->room . '/lock', [])->statusCode());
        self::assertError(409, 'rule_violation', $this->api('POST', 'devices/' . $first['id'] . '/state', ['version' => 1, 'location_id' => $this->room], $this->user));

        self::assertSame(200, $this->api('POST', 'locations/' . $this->room . '/unlock', [])->statusCode());
        self::assertSame(200, $this->api('POST', 'devices/' . $second['id'] . '/state', ['version' => 1, 'location_id' => $this->room], $this->user)->statusCode());
        $locked = $this->api('POST', 'locations/' . $this->room . '/lock', []);
        self::assertError(409, 'rule_violation', $locked);
        self::assertSame(['devices' => 1, 'sublocations' => 0], self::json($locked)['details']);
    }

    /**
     * Eine zweite Verbindung sperrt den Standort ($mode), während $work auf der Testverbindung läuft;
     * $work muss in einen Lock-Wait-Timeout laufen und seine Transaktion zurücknehmen.
     *
     * @param 'FOR UPDATE'|'LOCK IN SHARE MODE' $mode
     */
    private function whileConcurrent(string $mode, int $locationId, \Closure $work): void
    {
        $concurrent = TestDatabase::connect();
        $concurrent->beginTransaction();
        $concurrent->prepare('SELECT id FROM fe_locations WHERE id = ? ' . $mode)->execute([$locationId]);
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $work();
            self::fail('Die Änderung hat nicht auf die Sperre der zweiten Verbindung gewartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_LOCK_WAIT_TIMEOUT, $e->errorInfo[1] ?? null, $e->getMessage());
        } finally {
            $concurrent->rollBack();
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        self::assertFalse($this->pdo()->inTransaction());
    }
}
