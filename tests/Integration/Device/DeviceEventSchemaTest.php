<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_device_events: je Ereignisart nur die passenden Spalten, Von/Nach verschieden, Gerät, Benutzer
 * und Standorte aus derselben Stadt.
 */
#[CoversNothing]
final class DeviceEventSchemaTest extends DeviceSchemaTestCase
{
    /** Platzhalter in den Datenquellen, im Test durch die IDs der beiden Standorte ersetzt. */
    private const string LOCATION_A = 'location A';
    private const string LOCATION_B = 'location B';

    private int $tenantId;
    private int $userId;
    private int $locationA;
    private int $locationB;
    private int $deviceId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->userId = $this->createUser($this->tenantId);
        $this->locationA = $this->createLocation($this->tenantId);
        $this->locationB = $this->createLocation($this->tenantId);
        $this->deviceId = $this->createDevice($this->tenantId, $this->createDeviceType($this->tenantId), $this->locationA);
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function validEvents(): iterable
    {
        yield 'angelegt' => [['event_type' => 'created', 'to_location_id' => self::LOCATION_A, 'to_status' => 'ready']];
        yield 'importiert mit Kommentar' => [[
            'event_type' => 'imported', 'to_location_id' => self::LOCATION_A, 'to_status' => 'defective',
            'comment' => 'Import aus Excel-Liste 2025',
        ]];
        yield 'nur Umbuchung' => [[
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B,
        ]];
        yield 'nur Statusänderung' => [['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'defective']];
        yield 'Umbuchung und Statusänderung' => [[
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B,
            'from_status' => 'defective', 'to_status' => 'maintenance', 'comment' => 'Zur Wartung in die Werkstatt',
        ]];
        yield 'aussondern' => [['event_type' => 'state_changed', 'from_status' => 'defective', 'to_status' => 'retired']];
        yield 'wieder aufnehmen' => [['event_type' => 'state_changed', 'from_status' => 'retired', 'to_status' => 'ready']];
        yield 'Prüfdatum gesetzt' => [['event_type' => 'due_changed', 'to_due' => '2027-03-31']];
        yield 'Prüfdatum entfernt' => [['event_type' => 'due_changed', 'from_due' => '2027-03-31']];
        yield 'Prüfdatum geändert' => [['event_type' => 'due_changed', 'from_due' => '2027-03-31', 'to_due' => '2028-03-31']];
        yield 'Kennung nur Groß-/Kleinschreibung' => [[
            'event_type' => 'field_changed', 'field' => 'identifier', 'old_value' => 'ma-01', 'new_value' => 'MA-01',
        ]];
        yield 'Notiz entfernt' => [['event_type' => 'field_changed', 'field' => 'note', 'old_value' => 'Alt']];
        yield 'Seriennummer gesetzt' => [['event_type' => 'field_changed', 'field' => 'serial_number', 'new_value' => 'SN 1']];
        yield 'Gerätetyp' => [[
            'event_type' => 'field_changed', 'field' => 'device_type', 'old_value' => 'Funkgerät', 'new_value' => 'Atemschutzgerät',
        ]];
        yield 'Offline-Umbuchung mit Aktionszeit' => [[
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B,
            'occurred_at' => '2026-01-01 08:00:00',
        ]];
        yield 'nicht übernommen, älter' => [[
            'event_type' => 'state_changed', 'to_location_id' => self::LOCATION_B, 'applied' => 0, 'not_applied_reason' => 'older',
            'occurred_at' => '2026-01-01 08:00:00',
        ]];
        yield 'nicht übernommen, gleicher Standort wie aktuell' => [[
            'event_type' => 'state_changed', 'to_location_id' => self::LOCATION_A, 'to_status' => 'ready', 'applied' => 0,
            'not_applied_reason' => 'retired',
        ]];
        yield 'nicht übernommen, Standort gesperrt' => [[
            'event_type' => 'state_changed', 'to_status' => 'defective', 'applied' => 0, 'not_applied_reason' => 'location_locked',
        ]];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    #[DataProvider('validEvents')]
    public function testValidEventIsAccepted(array $values): void
    {
        $id = $this->event($values);

        $row = $this->pdo()->query(
            'SELECT TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age FROM fe_device_events WHERE id = ' . $id,
        )->fetch();
        self::assertLessThan(60, abs($row['age']));
    }

    public function testAllFieldsAreAccepted(): void
    {
        foreach (['identifier', 'scan_code', 'device_type', 'serial_number', 'manufacturer', 'model', 'procured_on', 'note'] as $field) {
            $this->event(['event_type' => 'field_changed', 'field' => $field, 'old_value' => 'alt', 'new_value' => 'neu']);
        }

        self::assertSame(8, $this->countRows('fe_device_events'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidEvents(): iterable
    {
        $created = ['event_type' => 'created', 'to_location_id' => self::LOCATION_A, 'to_status' => 'ready'];

        yield 'unbekannte Ereignisart' => [['event_type' => 'moved', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B]];
        yield 'Ereignisart großgeschrieben' => [['event_type' => 'Created', 'to_location_id' => self::LOCATION_A, 'to_status' => 'ready']];
        yield 'angelegt ohne Standort' => [['event_type' => 'created', 'to_status' => 'ready']];
        yield 'angelegt ohne Status' => [['event_type' => 'imported', 'to_location_id' => self::LOCATION_A]];
        yield 'angelegt mit Von-Standort' => [[...$created, 'from_location_id' => self::LOCATION_B]];
        yield 'angelegt mit Von-Status' => [[...$created, 'from_status' => 'defective']];
        yield 'angelegt ausgesondert' => [[...$created, 'to_status' => 'retired']];
        yield 'angelegt mit Prüfdatum' => [[...$created, 'to_due' => '2027-01-01']];
        yield 'angelegt mit Feld' => [[...$created, 'field' => 'note', 'new_value' => 'x']];
        yield 'Zustand ohne Änderung' => [['event_type' => 'state_changed']];
        yield 'Umbuchung auf denselben Standort' => [[
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_A,
        ]];
        yield 'Umbuchung ohne Von' => [['event_type' => 'state_changed', 'to_location_id' => self::LOCATION_B]];
        yield 'Umbuchung ohne Nach' => [['event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A]];
        yield 'gleicher Status' => [['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'ready']];
        yield 'Status ohne Von' => [['event_type' => 'state_changed', 'to_status' => 'defective']];
        yield 'Status unbekannt' => [['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'lost']];
        yield 'Von-Status unbekannt' => [['event_type' => 'state_changed', 'from_status' => 'Ready', 'to_status' => 'ready']];
        yield 'Zustand mit Prüfdatum' => [['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'defective', 'to_due' => '2027-01-01']];
        yield 'Prüfdatum gleich' => [['event_type' => 'due_changed', 'from_due' => '2027-01-01', 'to_due' => '2027-01-01']];
        yield 'Prüfdatum beide leer' => [['event_type' => 'due_changed']];
        yield 'Prüfdatum mit Standort' => [['event_type' => 'due_changed', 'to_due' => '2027-01-01', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B]];
        yield 'Prüfdatum mit Status' => [['event_type' => 'due_changed', 'to_due' => '2027-01-01', 'from_status' => 'ready', 'to_status' => 'defective']];
        yield 'Prüfdatum nach 2099' => [['event_type' => 'due_changed', 'to_due' => '2100-01-01']];
        yield 'Prüfdatum vor 1950' => [['event_type' => 'due_changed', 'from_due' => '1949-12-31', 'to_due' => '2027-01-01']];
        yield 'Feld ohne Namen' => [['event_type' => 'field_changed', 'old_value' => 'a', 'new_value' => 'b']];
        yield 'Feld unbekannt' => [['event_type' => 'field_changed', 'field' => 'status', 'old_value' => 'a', 'new_value' => 'b']];
        yield 'Feld großgeschrieben' => [['event_type' => 'field_changed', 'field' => 'Note', 'old_value' => 'a', 'new_value' => 'b']];
        yield 'Feld gleicher Wert' => [['event_type' => 'field_changed', 'field' => 'note', 'old_value' => 'Gleich', 'new_value' => 'Gleich']];
        yield 'Feld beide leer' => [['event_type' => 'field_changed', 'field' => 'note']];
        yield 'Feld mit Status' => [['event_type' => 'field_changed', 'field' => 'note', 'new_value' => 'x', 'from_status' => 'ready', 'to_status' => 'defective']];
        yield 'Wert ohne Feld bei Umbuchung' => [[
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => self::LOCATION_B, 'old_value' => 'x',
        ]];
        yield 'leerer Kommentar' => [[...$created, 'comment' => '  ']];
        $notApplied = ['event_type' => 'state_changed', 'to_location_id' => self::LOCATION_B, 'applied' => 0, 'not_applied_reason' => 'older'];
        yield 'nicht übernommen ohne Grund' => [[...$notApplied, 'not_applied_reason' => null]];
        yield 'übernommen mit Grund' => [['event_type' => 'state_changed', 'from_status' => 'ready', 'to_status' => 'defective', 'not_applied_reason' => 'older']];
        yield 'Grund unbekannt' => [[...$notApplied, 'not_applied_reason' => 'late']];
        yield 'Grund großgeschrieben' => [[...$notApplied, 'not_applied_reason' => 'Older']];
        yield 'applied 2' => [[...$notApplied, 'applied' => 2]];
        yield 'nicht übernommen mit Von-Standort' => [[...$notApplied, 'from_location_id' => self::LOCATION_A]];
        yield 'nicht übernommen mit Von-Status' => [[...$notApplied, 'from_status' => 'ready', 'to_status' => 'defective']];
        yield 'nicht übernommen ohne Ziel' => [['event_type' => 'state_changed', 'applied' => 0, 'not_applied_reason' => 'older']];
        yield 'nicht übernommen ausgesondert' => [['event_type' => 'state_changed', 'to_status' => 'retired', 'applied' => 0, 'not_applied_reason' => 'older']];
        yield 'angelegt nicht übernommen' => [[...$created, 'applied' => 0, 'not_applied_reason' => 'older']];
        yield 'Prüfdatum nicht übernommen' => [['event_type' => 'due_changed', 'to_due' => '2027-01-01', 'applied' => 0, 'not_applied_reason' => 'older']];
        yield 'Aktionszeit bei Anlegen' => [[...$created, 'occurred_at' => '2026-01-01 08:00:00']];
        yield 'Aktionszeit bei Stammdaten' => [['event_type' => 'field_changed', 'field' => 'note', 'new_value' => 'x', 'occurred_at' => '2026-01-01 08:00:00']];
    }

    /**
     * @param array<string, scalar|null> $values
     */
    #[DataProvider('invalidEvents')]
    public function testDatabaseRejectsInvalidEvent(array $values): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->event($values));
        self::assertSame(0, $this->countRows('fe_device_events'));
    }

    public function testDatabaseRejectsTooLongCommentOrValue(): void
    {
        $this->event(['event_type' => 'field_changed', 'field' => 'note', 'new_value' => str_repeat('ä', 1000), 'comment' => str_repeat('ö', 500)]);

        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->event([
            'event_type' => 'created', 'to_location_id' => self::LOCATION_A, 'to_status' => 'ready', 'comment' => str_repeat('ö', 501),
        ]));
        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->event([
            'event_type' => 'field_changed', 'field' => 'note', 'old_value' => str_repeat('ä', 1001),
        ]));
    }

    public function testAnonymizedUserIsAccepted(): void
    {
        $anonymizedId = $this->createUser($this->tenantId, [
            'status' => 'deleted',
            'email' => null,
            'email_verified_at' => null,
            'password_hash' => null,
            'name' => null,
            'display_name' => null,
            'deleted_at' => self::CREATED_AT,
        ]);

        $this->createEvent($this->tenantId, $this->deviceId, $anonymizedId, $this->created());

        self::assertSame(1, $this->countRows('fe_device_events'));
    }

    public function testDatabaseRejectsUserOfOtherTenantOrSuperadmin(): void
    {
        $foreignUserId = $this->createUser($this->createTenant('tuttlingen'));
        $superadminId = $this->createSuperadmin();

        foreach ([$foreignUserId, $superadminId] as $userId) {
            $this->assertRejected(
                self::ER_NO_REFERENCED_ROW,
                fn () => $this->createEvent($this->tenantId, $this->deviceId, $userId, $this->created()),
            );
        }
    }

    public function testDatabaseRejectsDeviceOrLocationOfOtherTenant(): void
    {
        $otherTenantId = $this->createTenant('tuttlingen');
        $foreignLocationId = $this->createLocation($otherTenantId);
        $foreignDeviceId = $this->createDevice($otherTenantId, $this->createDeviceType($otherTenantId), $foreignLocationId);

        $this->assertRejected(
            self::ER_NO_REFERENCED_ROW,
            fn () => $this->createEvent($this->tenantId, $foreignDeviceId, $this->userId, $this->created()),
        );
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->event([
            'event_type' => 'state_changed', 'from_location_id' => self::LOCATION_A, 'to_location_id' => $foreignLocationId,
        ]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->event([
            'event_type' => 'state_changed', 'from_location_id' => $foreignLocationId, 'to_location_id' => self::LOCATION_B,
        ]));
    }

    public function testDatabaseRejectsDeletingDeviceOrUserWithEvents(): void
    {
        $this->event($this->created());

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_devices WHERE id = ' . $this->deviceId),
        );
        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_users WHERE id = ' . $this->userId),
        );
        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_locations WHERE id = ' . $this->locationA),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function created(): array
    {
        return ['event_type' => 'created', 'to_location_id' => $this->locationA, 'to_status' => 'ready'];
    }

    /**
     * @param array<string, scalar|null> $values Standort-Platzhalter werden durch IDs ersetzt
     */
    private function event(array $values): int
    {
        $values = array_map(fn (mixed $value): mixed => match ($value) {
            self::LOCATION_A => $this->locationA,
            self::LOCATION_B => $this->locationB,
            default => $value,
        }, $values);

        return $this->createEvent($this->tenantId, $this->deviceId, $this->userId, $values);
    }
}
