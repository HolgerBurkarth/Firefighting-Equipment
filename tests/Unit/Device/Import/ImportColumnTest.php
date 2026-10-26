<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\Import\ImportColumn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportColumn::class)]
final class ImportColumnTest extends TestCase
{
    public function testColumnsAreTheExportColumnsInTheSameOrder(): void
    {
        self::assertSame(DeviceCsv::DEVICE_HEADER, array_map(static fn (ImportColumn $c): string => $c->label(), ImportColumn::cases()));
    }

    public function testHeaderIgnoresCaseAndSurroundingSpace(): void
    {
        self::assertSame(ImportColumn::Identifier, ImportColumn::fromHeader('Kennung'));
        self::assertSame(ImportColumn::Identifier, ImportColumn::fromHeader(' KENNUNG '));
        self::assertSame(ImportColumn::NextInspectionDue, ImportColumn::fromHeader('nächste prüfung'));
        self::assertSame(ImportColumn::NextInspectionDue, ImportColumn::fromHeader('NÄCHSTE PRÜFUNG'));
        self::assertSame(ImportColumn::DeviceType, ImportColumn::fromHeader('gerätetyp'));
    }

    public function testUnknownHeader(): void
    {
        self::assertNull(ImportColumn::fromHeader('Seriennumer'));
        self::assertNull(ImportColumn::fromHeader(''));
        self::assertNull(ImportColumn::fromHeader('Naechste Pruefung'));
    }

    public function testDeviceFields(): void
    {
        self::assertSame(DeviceField::Identifier, ImportColumn::Identifier->deviceField());
        self::assertSame(DeviceField::DeviceType, ImportColumn::DeviceType->deviceField());
        self::assertSame(DeviceField::Note, ImportColumn::Note->deviceField());
        self::assertNull(ImportColumn::Location->deviceField());
        self::assertNull(ImportColumn::Status->deviceField());
        self::assertNull(ImportColumn::NextInspectionDue->deviceField());
    }
}
