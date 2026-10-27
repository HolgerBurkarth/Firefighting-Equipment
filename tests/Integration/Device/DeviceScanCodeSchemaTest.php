<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_devices.scan_code (Migration 0019): optional, exakt (binär) eindeutig je Stadt nur unter nicht
 * ausgesonderten Geräten (scan_code_key), dieselben Regeln wie ScanCode per CHECK.
 */
#[CoversNothing]
final class DeviceScanCodeSchemaTest extends DeviceSchemaTestCase
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

    public function testScanCodeIsOptionalAndManyDevicesHaveNone(): void
    {
        $id = $this->device();
        $this->device();

        self::assertNull($this->deviceValue($id, 'scan_code'));
        self::assertNull($this->deviceValue($id, 'scan_code_key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validScanCodes(): iterable
    {
        yield 'GTIN' => ['4006381333931'];
        yield 'GS1 mit GS' => ["0104006381333931\x1D10ABC"];
        yield 'ISO/IEC 15434' => ["[)>\x1E06\x1D17V1\x1E\x04"];
        yield 'Leerzeichen im Inhalt' => ['A  B'];
        yield 'Umlaute' => ['Größe'];
        yield '200 Zeichen' => [str_repeat('ä', 200)];
    }

    #[DataProvider('validScanCodes')]
    public function testValidScanCodeIsStoredUnchanged(string $scanCode): void
    {
        $id = $this->device(['scan_code' => $scanCode]);

        self::assertSame($scanCode, $this->deviceValue($id, 'scan_code'));
        self::assertSame($scanCode, $this->deviceValue($id, 'scan_code_key'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidScanCodes(): iterable
    {
        yield 'leer' => [''];
        yield 'Leerzeichen am Anfang' => [' 4006'];
        yield 'Leerzeichen am Ende' => ['4006 '];
        yield 'nur Leerzeichen' => [' '];
        yield 'LF' => ["4006\n"];
        yield 'CR im Inhalt' => ["40\r06"];
        yield 'Tab' => ["\t4006"];
        yield 'NUL' => ["A\x00B"];
        yield 'FS (0x1C)' => ["A\x1CB"];
        yield 'US (0x1F)' => ["A\x1FB"];
        yield 'DEL' => ["A\x7FB"];
        yield 'C1' => ["A\u{0085}B"];
        yield 'Zero-Width-Space' => ["A\u{200B}B"];
        yield 'BOM' => ["\u{FEFF}A"];
        yield 'U+2029' => ["A\u{2029}B"];
    }

    #[DataProvider('invalidScanCodes')]
    public function testDatabaseRejectsInvalidScanCode(string $scanCode): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->device(['scan_code' => $scanCode]));
    }

    public function testDatabaseRejectsTooLongScanCode(): void
    {
        $this->assertRejected(self::ER_DATA_TOO_LONG, fn () => $this->device(['scan_code' => str_repeat('a', 201)]));
    }

    public function testDuplicateScanCodeIsRejected(): void
    {
        $this->device(['scan_code' => "0104006381333931\x1D10AB"]);

        $this->assertRejected(self::ER_DUP_ENTRY, fn () => $this->device(['scan_code' => "0104006381333931\x1D10AB"]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentScanCodes(): iterable
    {
        yield 'Groß- und Kleinschreibung' => ['abc', 'ABC'];
        yield 'ß und ss' => ['Straße', 'Strasse'];
        yield 'é und e' => ['Café', 'Cafe'];
        yield 'führende Nullen' => ['04006381333931', '4006381333931'];
        yield 'mit und ohne GS' => ["0104006381333931\x1D10AB", '010400638133393110AB'];
    }

    #[DataProvider('differentScanCodes')]
    public function testScanCodesAreComparedExactly(string $first, string $second): void
    {
        $this->device(['scan_code' => $first]);
        $this->device(['scan_code' => $second]);

        self::assertSame(2, $this->countRows('fe_devices'));
    }

    public function testSameScanCodeInOtherTenantIsAccepted(): void
    {
        $this->device(['scan_code' => '4006381333931']);
        $otherTenant = $this->createTenant('tuttlingen');
        $this->createDevice($otherTenant, $this->createDeviceType($otherTenant), $this->createLocation($otherTenant), ['scan_code' => '4006381333931']);

        self::assertSame(2, $this->countRows('fe_devices'));
    }

    public function testRetiringReleasesScanCodeAndReinstatingNeedsItFree(): void
    {
        $oldId = $this->device(['scan_code' => '4006381333931']);
        $this->pdo()->exec("UPDATE fe_devices SET status = 'retired' WHERE id = " . $oldId);
        self::assertNull($this->deviceValue($oldId, 'scan_code_key'));
        self::assertSame('4006381333931', $this->deviceValue($oldId, 'scan_code'), 'bleibt am ausgesonderten Gerät gespeichert');
        $newId = $this->device(['scan_code' => '4006381333931']);
        $this->device(['scan_code' => '4006381333931', 'status' => 'retired']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->pdo()->exec("UPDATE fe_devices SET status = 'ready' WHERE id = " . $oldId),
        );

        $this->pdo()->exec('UPDATE fe_devices SET scan_code = NULL WHERE id = ' . $newId);
        $this->pdo()->exec("UPDATE fe_devices SET status = 'defective' WHERE id = " . $oldId);
        self::assertSame('4006381333931', $this->deviceValue($oldId, 'scan_code_key'));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function device(array $overrides = []): int
    {
        return $this->createDevice($this->tenantId, $this->typeId, $this->locationId, $overrides);
    }
}
