<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceStatus::class)]
#[CoversClass(DeviceField::class)]
final class DeviceStatusTest extends TestCase
{
    public function testValuesMatchSchemaAndHaveGermanLabels(): void
    {
        self::assertSame(
            ['ready' => 'einsatzbereit', 'maintenance' => 'in Wartung', 'defective' => 'defekt', 'retired' => 'ausgesondert'],
            array_combine(
                array_map(static fn (DeviceStatus $s): string => $s->value, DeviceStatus::cases()),
                array_map(static fn (DeviceStatus $s): string => $s->label(), DeviceStatus::cases()),
            ),
        );
    }

    public function testFieldsMatchSchemaOfEvents(): void
    {
        self::assertSame(
            ['identifier', 'device_type', 'serial_number', 'manufacturer', 'model', 'procured_on', 'note'],
            array_map(static fn (DeviceField $f): string => $f->value, DeviceField::cases()),
        );
        self::assertSame('device_type_id', DeviceField::DeviceType->bodyKey());
        self::assertSame('serial_number', DeviceField::SerialNumber->bodyKey());
    }
}
