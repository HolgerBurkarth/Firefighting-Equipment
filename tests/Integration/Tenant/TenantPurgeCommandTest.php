<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Backup\BackupDirectory;
use FirefightingEquipment\Backup\BackupException;
use FirefightingEquipment\Backup\BackupFileName;
use FirefightingEquipment\Backup\BackupResult;
use FirefightingEquipment\Backup\BackupRotation;
use FirefightingEquipment\Backup\BackupTables;
use FirefightingEquipment\Backup\DatabaseBackup;
use FirefightingEquipment\Backup\DatabaseDumper;
use FirefightingEquipment\Backup\MysqlDefaultsFile;
use FirefightingEquipment\Cli\TenantCommand;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Device\DeviceDataCounts;
use FirefightingEquipment\Device\TenantDeviceDataPurge;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\Device\DeviceSchemaTestCase;
use FirefightingEquipment\Tests\Support\Mysqldump;
use FirefightingEquipment\Tests\Support\TempDirectory;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * bin/tenant.php purge-device-data: Probelauf, Bestätigung, Sicherung vorher, Löschen nur der
 * Gerätedaten genau einer Stadt.
 */
#[CoversClass(TenantCommand::class)]
#[CoversClass(TenantDeviceDataPurge::class)]
#[CoversClass(DeviceDataCounts::class)]
#[UsesClass(BackupDirectory::class)]
#[UsesClass(BackupException::class)]
#[UsesClass(BackupFileName::class)]
#[UsesClass(BackupResult::class)]
#[UsesClass(BackupRotation::class)]
#[UsesClass(BackupTables::class)]
#[UsesClass(DatabaseBackup::class)]
#[UsesClass(DatabaseDumper::class)]
#[UsesClass(MysqlDefaultsFile::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(ConnectionFactory::class)]
#[UsesClass(DatabaseConfig::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantNotFoundException::class)]
#[UsesClass(TenantRepository::class)]
#[UsesClass(TenantSlug::class)]
final class TenantPurgeCommandTest extends DeviceSchemaTestCase
{
    private const string BACKUP_PATH = '/sicherung/ff-db-20261026-120000Z-vor-purge-immendingen.sql.gz';

    private int $tenantId;
    private int $otherTenantId;

    /** @var list<array{string, array<string, int>}> Aufrufe der Fake-Sicherung: Zusatz, Anzahlen zu dem Zeitpunkt */
    private array $backups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant('immendingen');
        $this->otherTenantId = $this->createTenant('tuttlingen');
        $this->fillTenant($this->tenantId);
        $this->fillTenant($this->otherTenantId);
    }

    public function testDryRunShowsCountsAndChangesNothing(): void
    {
        $before = $this->snapshot();

        [$exitCode, $out, $err] = $this->runCommand(['purge-device-data', '--slug=immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('Probelauf für immendingen („Feuerwehr immendingen“) – es wird nichts geändert.', $out);
        self::assertMatchesRegularExpression('/^  Geräte:     3\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Ereignisse: 5\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Standorte:  3\r?$/m', $out);
        self::assertStringContainsString('purge-device-data --slug=immendingen --execute --confirm=immendingen', $out);
        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->backups);
    }

    public function testMissingOrWrongConfirmationAbortsWithoutChanges(): void
    {
        $before = $this->snapshot();

        foreach ([
            ['purge-device-data', '--slug=immendingen', '--execute'],
            ['purge-device-data', '--slug=immendingen', '--execute', '--confirm=tuttlingen'],
            ['purge-device-data', '--slug=immendingen', '--execute', '--confirm=Immendingen'],
        ] as $args) {
            [$exitCode, $out, $err] = $this->runCommand($args);

            self::assertSame(TenantCommand::EXIT_USAGE, $exitCode, implode(' ', $args));
            self::assertSame('', $out);
            self::assertStringStartsWith('FEHLER: --', $err);
        }

        self::assertSame($before, $this->snapshot());
        self::assertSame([], $this->backups);
    }

    public function testExecuteDeletesOnlyDeviceDataOfThisTenant(): void
    {
        $before = $this->snapshot();

        [$exitCode, $out, $err] = $this->runCommand(['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('Sicherung angelegt: ' . self::BACKUP_PATH, $out);
        self::assertStringContainsString('Gerätedaten gelöscht: immendingen („Feuerwehr immendingen“). Gelöscht:', $out);
        self::assertMatchesRegularExpression('/^  Geräte:     3\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Ereignisse: 5\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Standorte:  3\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Buchungen:  1\r?$/m', $out);
        self::assertMatchesRegularExpression('/^  Hinweise:   1\r?$/m', $out);

        // Erwartet: alles wie vorher außer Geräten, Ereignissen, Standorten, Quittungen und Hinweisen
        // dieser Stadt. Damit bleiben die andere Stadt vollständig, Gerätetypen, Benutzer, Codes,
        // Sitzungen und die Stadt selbst gleich.
        $expected = $before;
        foreach (['fe_device_events', 'fe_devices', 'fe_locations', 'fe_device_bookings', 'fe_device_notices', 'fe_device_notice_actions'] as $table) {
            $expected[$table] = array_values(array_filter(
                $before[$table],
                fn (array $row): bool => (int) $row['tenant_id'] !== $this->tenantId,
            ));
            self::assertNotSame($before[$table], $expected[$table], $table);
            self::assertNotSame([], $expected[$table], $table);
        }
        self::assertSame($expected, $this->snapshot());
        self::assertEquals(new DeviceDataCounts(0, 0, 0), $this->counts($this->tenantId));
        self::assertEquals(new DeviceDataCounts(3, 5, 3, 1, 1), $this->counts($this->otherTenantId));
    }

    public function testBackupIsCreatedBeforeDeleting(): void
    {
        $this->runCommand(['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen']);

        self::assertSame(
            [['vor-purge-immendingen', ['devices' => 3, 'events' => 5, 'locations' => 3]]],
            $this->backups,
        );
    }

    public function testBackupFailureAbortsWithoutDeleting(): void
    {
        $before = $this->snapshot();

        [$exitCode, $out, $err] = $this->runCommand(
            ['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen'],
            static function (): string {
                throw new BackupException('mysqldump ist fehlgeschlagen.');
            },
        );

        self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
        self::assertSame('', $out);
        self::assertSame('FEHLER: mysqldump ist fehlgeschlagen.' . PHP_EOL, $err);
        self::assertSame($before, $this->snapshot());
    }

    public function testDefaultBackupWithUnusableDirectoryAbortsWithoutDeleting(): void
    {
        $dir = new TempDirectory();
        $before = $this->snapshot();

        try {
            // Ohne .htaccess verweigert die echte Sicherung (DatabaseBackup) den Ordner.
            [$exitCode, $out, $err] = $this->runCommand(
                ['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen'],
                backup: null,
                backupDir: $dir->path,
            );

            self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
            self::assertSame('', $out);
            self::assertStringContainsString('fehlt die .htaccess', $err);
            self::assertSame(['.', '..'], scandir($dir->path));
        } finally {
            $dir->remove();
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testDefaultBackupWritesDumpWithDeviceDataBeforeDeleting(): void
    {
        if (!Mysqldump::available()) {
            self::markTestSkipped('mysqldump nicht im PATH.');
        }
        $dir = new TempDirectory();
        $dir->write('.htaccess', 'Require all denied');

        try {
            [$exitCode, $out, $err] = $this->runCommand(
                ['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen'],
                backup: null,
                backupDir: $dir->path,
            );

            self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
            $files = glob($dir->path . DIRECTORY_SEPARATOR . 'ff-db-*-vor-purge-immendingen.sql.gz') ?: [];
            self::assertCount(1, $files);
            self::assertStringContainsString('Sicherung angelegt: ' . realpath($files[0]), $out);
            $dump = (string) gzdecode((string) file_get_contents($files[0]));
            self::assertStringContainsString('INSERT INTO `fe_device_events`', $dump);
            self::assertStringContainsString('INSERT INTO `fe_locations`', $dump);
        } finally {
            $dir->remove();
        }

        self::assertEquals(new DeviceDataCounts(0, 0, 0), $this->counts($this->tenantId));
    }

    public function testNothingToDeleteNeedsNoBackup(): void
    {
        $emptyTenant = $this->createTenant('geisingen');
        $this->createDeviceType($emptyTenant);
        $before = $this->snapshot();

        [$exitCode, $out, $err] = $this->runCommand(['purge-device-data', '--slug=geisingen', '--execute', '--confirm=geisingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertSame('Keine Gerätedaten vorhanden: geisingen. Nichts gelöscht, keine Sicherung angelegt.' . PHP_EOL, $out);
        self::assertSame([], $this->backups);
        self::assertSame($before, $this->snapshot());
    }

    public function testUnknownTenantGivesExitCode1(): void
    {
        foreach ([['purge-device-data', '--slug=geisingen'], ['purge-device-data', '--slug=geisingen', '--execute', '--confirm=geisingen']] as $args) {
            [$exitCode, $out, $err] = $this->runCommand($args);

            self::assertSame(TenantCommand::EXIT_ERROR, $exitCode);
            self::assertSame('', $out);
            self::assertStringStartsWith('FEHLER: ', $err);
        }
        self::assertSame([], $this->backups);
    }

    public function testLockedTenantCanBePurged(): void
    {
        $this->pdo()->prepare('UPDATE fe_tenants SET active = 0 WHERE id = ?')->execute([$this->tenantId]);

        [$exitCode, , $err] = $this->runCommand(['purge-device-data', '--slug=immendingen', '--execute', '--confirm=immendingen']);

        self::assertSame(TenantCommand::EXIT_OK, $exitCode, $err);
        self::assertEquals(new DeviceDataCounts(0, 0, 0), $this->counts($this->tenantId));
    }

    public function testPurgeRefusesOpenTransaction(): void
    {
        $tenant = new TenantRepository($this->pdo())->findBySlug(TenantSlug::fromString('immendingen')) ?? self::fail('Stadt fehlt.');
        $this->pdo()->beginTransaction();

        try {
            new TenantDeviceDataPurge($this->pdo())->purge($tenant);
            self::fail('LogicException erwartet.');
        } catch (\LogicException) {
            self::assertTrue($this->pdo()->inTransaction());
        } finally {
            $this->pdo()->rollBack();
        }
    }

    /**
     * Gerätetyp, zwei Standorte der Ebene 1 (einer mit Unterstandort), drei Geräte mit fünf Ereignissen,
     * eine Offline-Buchung mit Hinweis (erledigt, mit Verlauf), dazu Benutzer, Einladungscode und Sitzung.
     */
    private function fillTenant(int $tenantId): void
    {
        $userId = $this->createUser($tenantId, ['role' => 'admin']);
        $this->createInvitationCode($tenantId, $userId);
        $this->insert('fe_sessions', [
            'user_id' => $userId,
            'token_hash' => random_bytes(32),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $typeId = $this->createDeviceType($tenantId);
        $vehicle = $this->createLocation($tenantId);
        $compartment = $this->createSubLocation($tenantId, $vehicle);
        $room = $this->createLocation($tenantId, ['kind' => 'room']);

        $devices = [
            $this->createDevice($tenantId, $typeId, $vehicle),
            $this->createDevice($tenantId, $typeId, $compartment),
            $this->createDevice($tenantId, $typeId, $room),
        ];
        foreach ($devices as $deviceId) {
            $this->createEvent($tenantId, $deviceId, $userId, ['event_type' => 'created', 'to_location_id' => $vehicle, 'to_status' => 'ready']);
        }
        $this->createEvent($tenantId, $devices[1], $userId, [
            'event_type' => 'state_changed',
            'from_location_id' => $vehicle,
            'to_location_id' => $compartment,
        ]);
        $event = $this->createEvent($tenantId, $devices[2], $userId, [
            'event_type' => 'state_changed',
            'from_location_id' => $vehicle,
            'to_location_id' => $room,
        ]);
        $booking = $this->insert('fe_device_bookings', [
            'tenant_id' => $tenantId, 'client_ref' => '3f2c8a1e-5b7d-4c9e-8f10-2a3b4c5d6e7f', 'user_id' => $userId,
            'device_id' => $devices[2], 'request_hash' => str_repeat("\0", 32), 'outcome' => 'applied', 'reason' => 'overwritten',
            'event_id' => $event,
        ]);
        $notice = $this->insert('fe_device_notices', [
            'tenant_id' => $tenantId, 'device_id' => $devices[2], 'booking_id' => $booking, 'event_id' => $event,
            'kind' => 'overwritten', 'user_id' => $userId, 'occurred_at' => '2026-01-01 08:00:00', 'requested_location_id' => $room,
            'other_occurred_at' => '2025-12-01 08:00:00', 'other_location_id' => $vehicle, 'other_status' => 'ready', 'status' => 'resolved',
        ]);
        $this->insert('fe_device_notice_actions', ['tenant_id' => $tenantId, 'notice_id' => $notice, 'action' => 'resolve', 'user_id' => $userId]);
    }

    private function counts(int $tenantId): DeviceDataCounts
    {
        $tenant = new TenantRepository($this->pdo())->findById($tenantId) ?? self::fail('Stadt fehlt.');

        return new TenantDeviceDataPurge($this->pdo())->count($tenant);
    }

    /**
     * Alle Zeilen aller Fachtabellen, nach Primärschlüssel sortiert.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function snapshot(): array
    {
        $tables = $this->pdo()->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
            . " AND TABLE_NAME LIKE 'fe\\_%' AND TABLE_NAME NOT LIKE 'fe\\_test\\_%'"
            . " AND TABLE_NAME <> 'fe_schema_migrations' ORDER BY TABLE_NAME",
        )->fetchAll(\PDO::FETCH_COLUMN);

        $result = [];
        foreach ($tables as $table) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->pdo()->query(sprintf('SELECT * FROM %s ORDER BY 1', $table))->fetchAll(\PDO::FETCH_ASSOC);
            $result[(string) $table] = $rows;
        }

        return $result;
    }

    /**
     * @param list<string> $args
     * @param (\Closure(DatabaseConfig, string): string)|null|false $backup false = Fake, der den Aufruf
     *        samt Anzahlen der Stadt in diesem Moment festhält
     *
     * @return array{int, string, string}
     */
    private function runCommand(array $args, \Closure|false|null $backup = false, string $backupDir = '/gibt-es-nicht'): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');

        if ($backup === false) {
            $backup = function (DatabaseConfig $config, string $suffix): string {
                $counts = $this->counts($this->tenantId);
                $this->backups[] = [$suffix, ['devices' => $counts->devices, 'events' => $counts->events, 'locations' => $counts->locations]];

                return self::BACKUP_PATH;
            };
        }

        $exitCode = new TenantCommand(TestDatabase::CONFIG_PATH, $out, $err, backup: $backup, backupDir: $backupDir)->run($args);

        rewind($out);
        rewind($err);
        $result = [$exitCode, (string) stream_get_contents($out), (string) stream_get_contents($err)];
        fclose($out);
        fclose($err);

        return $result;
    }
}
