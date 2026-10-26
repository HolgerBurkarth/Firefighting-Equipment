<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceEventType;
use FirefightingEquipment\Device\DeviceReinstatement;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\InvalidDeviceValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceEvent::class)]
#[CoversClass(DeviceEventType::class)]
#[CoversClass(DeviceEventLocation::class)]
#[CoversClass(DeviceStateChange::class)]
#[CoversClass(DeviceReinstatement::class)]
final class DeviceEventTest extends TestCase
{
    public function testTypesMatchSchemaAndHaveGermanLabels(): void
    {
        self::assertSame(
            [
                'created' => 'Angelegt',
                'imported' => 'Importiert',
                'state_changed' => 'Standort/Status geändert',
                'due_changed' => 'Prüfdatum geändert',
                'field_changed' => 'Stammdaten geändert',
            ],
            array_combine(
                array_map(static fn (DeviceEventType $t): string => $t->value, DeviceEventType::cases()),
                array_map(static fn (DeviceEventType $t): string => $t->label(), DeviceEventType::cases()),
            ),
        );
    }

    /**
     * @return iterable<string, array{bool, ?DeviceStatus, ?DeviceStatus, string}>
     */
    public static function stateChanges(): iterable
    {
        yield 'Umbuchung' => [true, null, null, 'Umgebucht'];
        yield 'Status' => [false, DeviceStatus::Ready, DeviceStatus::Defective, 'Status geändert'];
        yield 'beides' => [true, DeviceStatus::Ready, DeviceStatus::Maintenance, 'Umgebucht und Status geändert'];
        yield 'Aussondern' => [false, DeviceStatus::Defective, DeviceStatus::Retired, 'Ausgesondert'];
        yield 'Wiederaufnahme' => [false, DeviceStatus::Retired, DeviceStatus::Ready, 'Wieder aufgenommen'];
        yield 'Wiederaufnahme mit Umbuchung' => [true, DeviceStatus::Retired, DeviceStatus::Ready, 'Wieder aufgenommen'];
    }

    #[DataProvider('stateChanges')]
    public function testStateChangeLabelDependsOnContent(bool $moved, ?DeviceStatus $from, ?DeviceStatus $to, string $label): void
    {
        self::assertSame($label, self::event(DeviceEventType::StateChanged, $moved, $from, $to)->label());
    }

    public function testOtherTypesUseTypeLabel(): void
    {
        self::assertSame('Angelegt', self::event(DeviceEventType::Created, false, null, DeviceStatus::Ready)->label());
        self::assertSame('Prüfdatum geändert', self::event(DeviceEventType::DueChanged, false, null, null)->label());
    }

    public function testLocationPath(): void
    {
        self::assertSame('HLF 20 / G1', new DeviceEventLocation(2, 'G1', 'HLF 20')->path());
        self::assertSame('Lager', new DeviceEventLocation(3, 'Lager', null)->path());
    }

    public function testStateChangeNeedsLocationOrStatus(): void
    {
        self::assertSame(DeviceStatus::Defective, new DeviceStateChange(null, DeviceStatus::Defective)->status);
        self::assertSame(5, new DeviceStateChange(5, null)->locationId);

        $this->expectException(InvalidDeviceValueException::class);
        new DeviceStateChange(null, null);
    }

    public function testStateChangeIsNeverRetirement(): void
    {
        $this->expectException(\LogicException::class);
        new DeviceStateChange(5, DeviceStatus::Retired);
    }

    public function testReinstatementIsNeverRetired(): void
    {
        self::assertNull(new DeviceReinstatement(DeviceStatus::Ready)->locationId);

        $this->expectException(\LogicException::class);
        new DeviceReinstatement(DeviceStatus::Retired);
    }

    private static function event(DeviceEventType $type, bool $moved, ?DeviceStatus $from, ?DeviceStatus $to): DeviceEvent
    {
        return new DeviceEvent(
            1,
            $type,
            7,
            'Erika',
            $moved ? new DeviceEventLocation(2, 'G1', 'HLF 20') : null,
            $moved || $type === DeviceEventType::Created ? new DeviceEventLocation(3, 'Lager', null) : null,
            $from,
            $to,
            null,
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-10-26 08:00:00', new \DateTimeZone('UTC')),
        );
    }
}
