<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Migration\SqlSplitter;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Migrationen 0023/0024: state_changed_at und state_version am Gerät. DomainTestCase führt 0023 auf
 * leeren Tabellen aus; hier laufen ihre Startwerte (nur DML) noch einmal mit vorhandenen Geräten.
 */
#[CoversNothing]
final class DeviceStateColumnsMigrationTest extends DeviceSchemaTestCase
{
    private const int ER_BAD_NULL = 1048;

    private const string MIGRATION = self::MIGRATIONS_DIR . '/0023_add_fe_devices_state_changed_at.sql';

    public function testStartValuesComeFromTheNewestStateEvent(): void
    {
        $tenantId = $this->createTenant();
        $otherTenantId = $this->createTenant('tuttlingen');
        $userId = $this->createUser($tenantId);
        $otherUserId = $this->createUser($otherTenantId);
        $type = $this->createDeviceType($tenantId);
        $room = $this->createLocation($tenantId, ['name' => 'Lager']);
        $hlf = $this->createLocation($tenantId, ['name' => 'HLF 20']);

        // Umgebucht, danach nur Prüfdatum und Stammdaten: zählt die Umbuchung.
        $moved = $this->createDevice($tenantId, $type, $hlf, ['version' => 4, 'created_at' => '2025-12-01 08:00:00']);
        $this->createEvent($tenantId, $moved, $userId, ['event_type' => 'created', 'to_location_id' => $room, 'to_status' => 'ready', 'created_at' => '2026-01-01 10:00:00']);
        $this->createEvent($tenantId, $moved, $userId, ['event_type' => 'state_changed', 'from_location_id' => $room, 'to_location_id' => $hlf, 'created_at' => '2026-02-01 11:30:00']);
        $this->createEvent($tenantId, $moved, $userId, ['event_type' => 'due_changed', 'to_due' => '2027-01-01', 'created_at' => '2026-03-01 09:00:00']);
        $this->createEvent($tenantId, $moved, $userId, ['event_type' => 'field_changed', 'field' => 'note', 'new_value' => 'x', 'created_at' => '2026-04-01 09:00:00']);

        // Nur importiert.
        $imported = $this->createDevice($tenantId, $type, $room, ['created_at' => '2026-01-15 07:00:00']);
        $this->createEvent($tenantId, $imported, $userId, ['event_type' => 'imported', 'to_location_id' => $room, 'to_status' => 'ready', 'created_at' => '2026-01-15 07:00:01']);

        // Ausgesondert: Aussondern ist eine Änderung des Stands.
        $retired = $this->createDevice($tenantId, $type, $room, ['status' => 'retired', 'version' => 2]);
        $this->createEvent($tenantId, $retired, $userId, ['event_type' => 'created', 'to_location_id' => $room, 'to_status' => 'ready', 'created_at' => '2026-01-01 10:00:00']);
        $this->createEvent($tenantId, $retired, $userId, ['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'retired', 'created_at' => '2026-05-05 05:05:05']);

        // Ohne Ereignis (nur per SQL angelegt): Zeitpunkt des Anlegens.
        $withoutEvents = $this->createDevice($tenantId, $type, $room, ['version' => 3, 'created_at' => '2025-05-05 12:00:00']);

        // Andere Stadt: eigene Ereignisse.
        $otherType = $this->createDeviceType($otherTenantId);
        $otherRoom = $this->createLocation($otherTenantId);
        $other = $this->createDevice($otherTenantId, $otherType, $otherRoom);
        $this->createEvent($otherTenantId, $other, $otherUserId, ['event_type' => 'created', 'to_location_id' => $otherRoom, 'to_status' => 'ready', 'created_at' => '2026-06-06 06:06:06']);

        $this->pdo()->exec("UPDATE fe_devices SET state_changed_at = '2000-01-01 00:00:00', state_version = 1");
        $this->runStartValues();

        self::assertSame(['2026-02-01 11:30:00', 4], $this->stateColumns($moved));
        self::assertSame(['2026-01-15 07:00:01', 1], $this->stateColumns($imported));
        self::assertSame(['2026-05-05 05:05:05', 2], $this->stateColumns($retired));
        self::assertSame(['2025-05-05 12:00:00', 3], $this->stateColumns($withoutEvents));
        self::assertSame(['2026-06-06 06:06:06', 1], $this->stateColumns($other));
    }

    public function testSchemaRequiresValidValues(): void
    {
        $tenantId = $this->createTenant();
        $device = $this->createDevice($tenantId, $this->createDeviceType($tenantId), $this->createLocation($tenantId), ['version' => 3]);

        $row = $this->pdo()->query('SELECT state_version, TIMESTAMPDIFF(SECOND, state_changed_at, UTC_TIMESTAMP()) AS age FROM fe_devices WHERE id = ' . $device)->fetch();
        self::assertSame(1, $row['state_version']);
        self::assertLessThan(60, abs($row['age']));

        $this->pdo()->exec('UPDATE fe_devices SET state_version = 3 WHERE id = ' . $device);
        $this->assertDatabaseError(self::ER_CONSTRAINT_FAILED, 'UPDATE fe_devices SET state_version = 4 WHERE id = ' . $device);
        $this->assertDatabaseError(self::ER_CONSTRAINT_FAILED, 'UPDATE fe_devices SET state_version = 0 WHERE id = ' . $device);
        $this->assertDatabaseError(self::ER_BAD_NULL, 'UPDATE fe_devices SET state_changed_at = NULL WHERE id = ' . $device);
    }

    /**
     * @return array{string, int}
     */
    private function stateColumns(int $deviceId): array
    {
        $row = $this->pdo()->query('SELECT state_changed_at, state_version FROM fe_devices WHERE id = ' . $deviceId)->fetch();

        return [(string) $row['state_changed_at'], (int) $row['state_version']];
    }

    private function assertDatabaseError(int $code, string $sql): void
    {
        try {
            $this->pdo()->exec($sql);
            self::fail('Fehler erwartet: ' . $sql);
        } catch (\PDOException $e) {
            self::assertSame($code, $e->errorInfo[1] ?? null, $e->getMessage());
        }
    }

    private function runStartValues(): void
    {
        $content = file_get_contents(self::MIGRATION);
        self::assertIsString($content);
        foreach (SqlSplitter::split($content) as $statement) {
            if (!str_starts_with(ltrim(preg_replace('/^--.*$/m', '', $statement) ?? ''), 'ALTER')) {
                $this->pdo()->exec($statement);
            }
        }
    }
}
