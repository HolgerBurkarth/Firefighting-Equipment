<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Cli\SeedTestCityCommand;
use FirefightingEquipment\Device\DeviceDataCounts;
use FirefightingEquipment\Device\Seed\TestCityData;
use FirefightingEquipment\Device\Seed\TestCitySeeder;
use FirefightingEquipment\Device\Seed\TestCitySeedException;
use FirefightingEquipment\Device\Seed\TestCitySeedResult;
use FirefightingEquipment\Device\TenantDeviceDataPurge;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Support\FixedClock;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * bin/seed-test-city.php gegen die Testdatenbank: Probelauf, Anlegen über die Repositorys (mit
 * Historie), Abbruch bei vorhandenen Daten oder ohne Admin, andere Städte unberührt.
 */
#[CoversClass(SeedTestCityCommand::class)]
#[CoversClass(TestCitySeeder::class)]
#[CoversClass(TestCitySeedResult::class)]
#[CoversClass(TestCitySeedException::class)]
#[CoversClass(TestCityData::class)]
final class SeedTestCityCommandTest extends DeviceSchemaTestCase
{
    /** Heute (Europe/Berlin) für die Prüfdaten. */
    private const string NOW = '2026-10-29 10:00:00';

    private int $testTenantId;
    private int $adminId;
    private int $userId;
    private int $otherTenantId;

    protected function setUp(): void
    {
        parent::setUp();

        // Wie über die API angelegt: mit Start-Gerätetypen.
        $this->testTenantId = new TenantRepository($this->pdo())->create(TenantSlug::fromString('test'), TenantName::fromString('Feuerwehr Test'))->id;
        $this->adminId = $this->createUser($this->testTenantId, ['role' => 'admin']);
        $this->userId = $this->createUser($this->testTenantId);

        $this->otherTenantId = $this->createTenant('immendingen');
        $this->createUser($this->otherTenantId, ['role' => 'admin']);
        $type = $this->createDeviceType($this->otherTenantId);
        $this->createDevice($this->otherTenantId, $type, $this->createLocation($this->otherTenantId));
    }

    public function testDryRunChangesNothing(): void
    {
        $before = $this->counts($this->testTenantId);

        [$exitCode, $out, $err] = $this->runCommand(['--slug=test']);

        self::assertSame(SeedTestCityCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('Probelauf für test („Feuerwehr Test“) – es wird nichts geändert.', $out);
        self::assertStringContainsString('Standorte: 15 (6 Standorte, 9 Fächer)', $out);
        self::assertStringContainsString('Geräte:    60 (ausgesuchte Testgeräte)', $out);
        self::assertStringContainsString('Verlauf:   15 Schritte an 7 Geräten, davon 1 ausgesondert', $out);
        self::assertStringContainsString(sprintf('Admin #%d legt an; Verlauf reihum von 2 Konto/Konten', $this->adminId), $out);
        self::assertStringContainsString('php bin/seed-test-city.php --slug=test --execute', $out);
        self::assertEquals($before, $this->counts($this->testTenantId));
        self::assertTrue($before->isEmpty());
    }

    public function testExecuteCreatesDataWithHistory(): void
    {
        $other = $this->counts($this->otherTenantId);

        [$exitCode, $out, $err] = $this->runCommand(['--slug=test', '--execute']);

        self::assertSame(SeedTestCityCommand::EXIT_OK, $exitCode, $err);
        self::assertStringContainsString('Testdaten angelegt: test („Feuerwehr Test“).', $out);
        $counts = $this->counts($this->testTenantId);
        self::assertSame([15, 60, 60 + 15], [$counts->locations, $counts->devices, $counts->events]);
        self::assertEquals($other, $this->counts($this->otherTenantId), 'Andere Städte bleiben unberührt.');

        // Verlauf: M2 in die Werkstatt und zurück (Version 3), WBK 1 auf ganzem Fahrzeug, M24 ausgesondert.
        self::assertSame(['HLF 20 / G1', 'ready', 3], $this->device('M2'));
        self::assertSame(['HLF 20 / G3', 'ready', 4], $this->device('PA 3'));
        self::assertSame(['HLF 20', 'ready', 3], $this->device('WBK 1'));
        self::assertSame(['Atemschutzwerkstatt', 'retired', 3], $this->device('M24'));
        self::assertSame(['MTW', 'ready', 1], $this->device('HRT 11'));
        // GS1-Code mit Trennzeichen GS gespeichert.
        self::assertSame("0104048675000017\x1D21ARHL-2001", $this->value("SELECT scan_code FROM fe_devices WHERE identifier = 'PA 1'"));
        // Anlegen mit Kommentar „Testdaten“ durch den Admin, Verlauf reihum von beiden Konten.
        self::assertSame(60, (int) $this->value(
            "SELECT COUNT(*) FROM fe_device_events WHERE tenant_id = ? AND event_type = 'created' AND comment = 'Testdaten' AND user_id = ?",
            [$this->testTenantId, $this->adminId],
        ));
        self::assertSame(2, (int) $this->value(
            "SELECT COUNT(DISTINCT user_id) FROM fe_device_events WHERE tenant_id = ? AND event_type = 'state_changed'",
            [$this->testTenantId],
        ));
        self::assertSame((string) $this->userId, (string) $this->value(
            "SELECT MAX(user_id) FROM fe_device_events WHERE tenant_id = ? AND event_type = 'state_changed'",
            [$this->testTenantId],
        ));
        // Prüfdaten relativ zu heute: überfällig, in 30 Tagen fällig, ohne Datum.
        self::assertGreaterThan(0, (int) $this->value("SELECT COUNT(*) FROM fe_devices WHERE tenant_id = ? AND next_inspection_due < '2026-10-29'", [$this->testTenantId]));
        self::assertGreaterThan(0, (int) $this->value(
            "SELECT COUNT(*) FROM fe_devices WHERE tenant_id = ? AND next_inspection_due BETWEEN '2026-10-29' AND '2026-11-28'",
            [$this->testTenantId],
        ));
        self::assertGreaterThan(0, (int) $this->value('SELECT COUNT(*) FROM fe_devices WHERE tenant_id = ? AND next_inspection_due IS NULL', [$this->testTenantId]));
    }

    public function testNumberedDevices(): void
    {
        [$exitCode, $out, $err] = $this->runCommand(['--slug=test', '--devices=30', '--execute']);

        self::assertSame(SeedTestCityCommand::EXIT_OK, $exitCode, $err);
        self::assertStringContainsString('Geräte:    30', $out);
        self::assertSame(30, $this->counts($this->testTenantId)->devices);
        self::assertSame('T0001', $this->value('SELECT MIN(identifier) FROM fe_devices WHERE tenant_id = ?', [$this->testTenantId]));
        self::assertSame('T0030', $this->value('SELECT MAX(identifier) FROM fe_devices WHERE tenant_id = ?', [$this->testTenantId]));
        self::assertSame('retired', $this->device('T0007')[1]);
    }

    public function testRefusesCityWithDeviceData(): void
    {
        $this->createLocation($this->testTenantId, ['name' => 'Schon da']);
        $before = $this->counts($this->testTenantId);

        [$exitCode, $out, $err] = $this->runCommand(['--slug=test', '--execute']);

        self::assertSame(SeedTestCityCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertStringContainsString('Die Stadt test hat schon Gerätedaten (0 Geräte, 1 Standorte).', $err);
        self::assertStringContainsString('purge-device-data --slug=test', $err);
        self::assertEquals($before, $this->counts($this->testTenantId));
    }

    public function testRefusesCityWithoutActiveAdmin(): void
    {
        $this->pdo()->prepare('UPDATE fe_users SET locked_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$this->adminId]);

        [$exitCode, , $err] = $this->runCommand(['--slug=test', '--execute']);

        self::assertSame(SeedTestCityCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('hat keinen aktiven Admin', $err);
        self::assertTrue($this->counts($this->testTenantId)->isEmpty());
    }

    public function testOtherSlugIsRefusedEvenIfTheCityExists(): void
    {
        $before = $this->counts($this->otherTenantId);

        [$exitCode, , $err] = $this->runCommand(['--slug=immendingen', '--execute']);

        self::assertSame(SeedTestCityCommand::EXIT_USAGE, $exitCode);
        self::assertStringContainsString('nur für die Test-Stadt', $err);
        self::assertEquals($before, $this->counts($this->otherTenantId));
    }

    public function testMissingTestCity(): void
    {
        $this->pdo()->prepare("UPDATE fe_tenants SET slug = 'test-alt' WHERE id = ?")->execute([$this->testTenantId]);

        [$exitCode, , $err] = $this->runCommand(['--slug=test']);

        self::assertSame(SeedTestCityCommand::EXIT_ERROR, $exitCode);
        self::assertStringContainsString('Keine Stadt mit dem Kürzel „test“ gefunden.', $err);
    }

    private function counts(int $tenantId): DeviceDataCounts
    {
        $tenant = new TenantRepository($this->pdo())->findById($tenantId) ?? self::fail('Stadt fehlt.');

        return new TenantDeviceDataPurge($this->pdo())->count($tenant);
    }

    /**
     * @return array{string, string, int} Standortpfad, Status, Version
     */
    private function device(string $identifier): array
    {
        $stmt = $this->pdo()->prepare(
            "SELECT IF(p.id IS NULL, l.name, CONCAT(p.name, ' / ', l.name)) AS path, d.status, d.version FROM fe_devices d"
            . ' JOIN fe_locations l ON l.id = d.location_id LEFT JOIN fe_locations p ON p.id = l.parent_id'
            . ' WHERE d.tenant_id = ? AND d.identifier = ?',
        );
        $stmt->execute([$this->testTenantId, $identifier]);
        /** @var array{path: string, status: string, version: int|string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, $identifier);

        return [$row['path'], $row['status'], (int) $row['version']];
    }

    /**
     * @param list<scalar> $params
     */
    private function value(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');

        $exitCode = new SeedTestCityCommand(TestDatabase::CONFIG_PATH, $out, $err, new FixedClock(self::NOW))->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }
}
