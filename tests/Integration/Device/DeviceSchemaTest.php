<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_devices: Kennung (Format, eindeutig je Stadt unter nicht ausgesonderten Geräten, ohne Groß- und
 * Kleinschreibung, ohne ß/ss-Gleichsetzung), Typ und Standort aus derselben Stadt, gültige Werte.
 */
#[CoversNothing]
final class DeviceSchemaTest extends DeviceSchemaTestCase
{
    private int $tenantId;
    private int $typeId;
    private int $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
        $this->typeId = $this->createDeviceType($this->tenantId);
        $this->locationId = $this->createLocation($this->tenantId);
    }

    public function testMinimalDeviceIsAcceptedWithDefaults(): void
    {
        $id = $this->device(['identifier' => 'MA-01']);

        $row = $this->pdo()->query(
            'SELECT identifier, identifier_key, status, serial_number, manufacturer, model, procured_on,'
            . ' next_inspection_due, note, version FROM fe_devices WHERE id = ' . $id,
        )->fetch();

        self::assertSame([
            'identifier' => 'MA-01',
            'identifier_key' => 'ma-01',
            'status' => 'ready',
            'serial_number' => null,
            'manufacturer' => null,
            'model' => null,
            'procured_on' => null,
            'next_inspection_due' => null,
            'note' => null,
            'version' => 1,
        ], $row);
    }

    public function testFullDeviceIsAccepted(): void
    {
        $id = $this->device([
            'status' => 'maintenance',
            'serial_number' => 'SN 4711',
            'manufacturer' => 'Dräger',
            'model' => 'FPS 7000',
            'procured_on' => '1950-01-01',
            'next_inspection_due' => '2099-12-31',
            'note' => "Zweizeilige\nNotiz",
            'version' => 7,
        ]);

        self::assertSame('Dräger', $this->deviceValue($id, 'manufacturer'));
        self::assertSame('2099-12-31', $this->deviceValue($id, 'next_inspection_due'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'einfach' => ['MA-01'];
        yield 'mit Leerzeichen' => ['Straße 1'];
        yield 'Umlaute und Akzente' => ['ÄÖÜ/é.x'];
        yield 'großes ẞ' => ['STRAẞE'];
        yield 'alle Sonderzeichen' => ['A.B/C-D 1'];
        yield 'ein Zeichen' => ['7'];
        yield '30 Zeichen' => [str_repeat('Ä', 30)];
    }

    #[DataProvider('validIdentifiers')]
    public function testValidIdentifierIsAccepted(string $identifier): void
    {
        $id = $this->device(['identifier' => $identifier]);

        self::assertSame($identifier, $this->deviceValue($id, 'identifier'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'leer' => [''];
        yield 'Leerzeichen am Anfang' => [' MA'];
        yield 'Leerzeichen am Ende' => ['MA '];
        yield 'doppeltes Leerzeichen' => ['MA  01'];
        yield 'Unterstrich' => ['MA_01'];
        yield 'Raute' => ['MA#1'];
        yield 'Zeilenumbruch am Ende' => ["MA\n"];
        yield 'Tabulator' => ["MA\t01"];
        yield 'geschütztes Leerzeichen' => ["MA\u{00A0}01"];
        yield 'zerlegter Umlaut' => ["a\u{0308}"];
        yield 'Bruch' => ['½'];
        yield 'Hochzahl' => ['²'];
        yield 'arabische Ziffer' => ["\u{0663}"];
    }

    #[DataProvider('invalidIdentifiers')]
    public function testDatabaseRejectsInvalidIdentifier(string $identifier): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->device(['identifier' => $identifier]));
        self::assertSame(0, $this->countRows('fe_devices'));
    }

    public function testDatabaseRejectsTooLongIdentifier(): void
    {
        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->device(['identifier' => str_repeat('A', 31)]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sameIdentifiers(): iterable
    {
        yield 'Großbuchstaben' => ['MA-01', 'ma-01'];
        yield 'Umlaute' => ['ÄRZTE', 'ärzte'];
        yield 'großes ẞ' => ['STRAẞE', 'straße'];
    }

    #[DataProvider('sameIdentifiers')]
    public function testDatabaseRejectsDuplicateIdentifierIgnoringCase(string $first, string $second): void
    {
        $this->device(['identifier' => $first]);

        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->device(['identifier' => $second]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentIdentifiers(): iterable
    {
        yield 'ß und ss' => ['Straße', 'Strasse'];
        yield 'é und e' => ['Café', 'Cafe'];
        yield 'ä und a' => ['Ärzte', 'Arzte'];
    }

    #[DataProvider('differentIdentifiers')]
    public function testSimilarIdentifiersAreDifferent(string $first, string $second): void
    {
        $this->device(['identifier' => $first]);
        $this->device(['identifier' => $second]);

        self::assertSame(2, $this->countRows('fe_devices'));
    }

    public function testSameIdentifierInOtherTenantIsAccepted(): void
    {
        $otherTenantId = $this->createTenant('tuttlingen');
        $this->device(['identifier' => 'MA-01']);
        $this->createDevice(
            $otherTenantId,
            $this->createDeviceType($otherTenantId),
            $this->createLocation($otherTenantId),
            ['identifier' => 'MA-01'],
        );

        self::assertSame(2, $this->countRows('fe_devices'));
    }

    public function testRetiredDevicesReleaseTheirIdentifier(): void
    {
        $retiredId = $this->device(['identifier' => 'MA-01', 'status' => 'retired']);
        $this->device(['identifier' => 'ma-01', 'status' => 'retired']);
        $this->device(['identifier' => 'Ma-01']);

        self::assertNull($this->deviceValue($retiredId, 'identifier_key'));
        self::assertSame(3, $this->countRows('fe_devices'));
    }

    public function testRetiringReleasesIdentifierAndReinstatingNeedsItFree(): void
    {
        $oldId = $this->device(['identifier' => 'MA-01']);
        $this->pdo()->exec("UPDATE fe_devices SET status = 'retired' WHERE id = " . $oldId);
        $newId = $this->device(['identifier' => 'MA-01']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->pdo()->exec("UPDATE fe_devices SET status = 'ready' WHERE id = " . $oldId),
        );

        $this->pdo()->exec("UPDATE fe_devices SET identifier = 'MA-02' WHERE id = " . $newId);
        $this->pdo()->exec("UPDATE fe_devices SET status = 'defective' WHERE id = " . $oldId);
        self::assertSame('ma-01', $this->deviceValue($oldId, 'identifier_key'));
    }

    public function testRenamingToTakenIdentifierIsRejected(): void
    {
        $this->device(['identifier' => 'MA-01']);
        $otherId = $this->device(['identifier' => 'MA-02']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->pdo()->exec("UPDATE fe_devices SET identifier = 'MA-01' WHERE id = " . $otherId),
        );
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidDevices(): iterable
    {
        yield 'unbekannter Status' => [['status' => 'lost']];
        yield 'Status großgeschrieben' => [['status' => 'Ready']];
        yield 'leere Seriennummer' => [['serial_number' => '  ']];
        yield 'leerer Hersteller' => [['manufacturer' => '']];
        yield 'leeres Modell' => [['model' => ' ']];
        yield 'leere Notiz' => [['note' => '   ']];
        yield 'Beschaffung vor 1950' => [['procured_on' => '1949-12-31']];
        yield 'Beschaffung nach 2099' => [['procured_on' => '2100-01-01']];
        yield 'Prüfung vor 1950' => [['next_inspection_due' => '0202-05-01']];
        yield 'Prüfung nach 2099' => [['next_inspection_due' => '2100-01-01']];
        yield 'Version 0' => [['version' => 0]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidDevices')]
    public function testDatabaseRejectsInvalidDevice(array $overrides): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->device($overrides));
        self::assertSame(0, $this->countRows('fe_devices'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'Beschaffung 30. Februar' => ['procured_on', '2026-02-30'];
        yield 'Prüfung kein Datum' => ['next_inspection_due', 'bald'];
    }

    #[DataProvider('invalidDates')]
    public function testDatabaseRejectsInvalidDate(string $column, string $value): void
    {
        $this->assertRejected(self::ER_TRUNCATED_WRONG_VALUE, fn () => $this->device([$column => $value]));
    }

    public function testDatabaseRejectsTooLongTexts(): void
    {
        $this->device(['serial_number' => str_repeat('x', 100), 'note' => str_repeat('ä', 1000)]);

        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->device(['model' => str_repeat('x', 101)]));
        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->device(['note' => str_repeat('ä', 1001)]));
    }

    public function testDatabaseRejectsTypeOrLocationOfOtherTenant(): void
    {
        $otherTenantId = $this->createTenant('tuttlingen');
        $foreignTypeId = $this->createDeviceType($otherTenantId);
        $foreignLocationId = $this->createLocation($otherTenantId);

        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->device(['device_type_id' => $foreignTypeId]));
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->device(['location_id' => $foreignLocationId]));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function device(array $overrides = []): int
    {
        return $this->createDevice($this->tenantId, $this->typeId, $this->locationId, $overrides);
    }
}
