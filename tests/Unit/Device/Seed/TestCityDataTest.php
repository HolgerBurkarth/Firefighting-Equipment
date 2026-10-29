<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Seed;

use FirefightingEquipment\Device\DefaultDeviceTypes;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\Seed\SeedDevice;
use FirefightingEquipment\Device\Seed\SeedStep;
use FirefightingEquipment\Device\Seed\TestCityData;
use FirefightingEquipment\Device\ScanCode;
use FirefightingEquipment\Time\LocalDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Datenplan der Testdaten: gültige Werte, eindeutige Kennungen und Scan-Codes, alle Fälle für die
 * App (Prüfdaten, ganzes Fahrzeug, GS1, Verlauf, ausgesondert).
 */
#[CoversClass(TestCityData::class)]
#[CoversClass(SeedDevice::class)]
#[CoversClass(SeedStep::class)]
#[UsesClass(DeviceIdentifier::class)]
#[UsesClass(ScanCode::class)]
#[UsesClass(DeviceDate::class)]
#[UsesClass(DeviceText::class)]
#[UsesClass(LocalDate::class)]
final class TestCityDataTest extends TestCase
{
    private const string TODAY = '2026-10-29';

    /**
     * @return iterable<string, array{?int}>
     */
    public static function variants(): iterable
    {
        yield 'ausgesuchte Geräte' => [null];
        yield '2500 Geräte' => [2500];
        yield 'ein Gerät' => [1];
    }

    public function testSelectedDevicesAndLocations(): void
    {
        $devices = TestCityData::devices(self::TODAY);

        self::assertCount(60, $devices);
        self::assertSame(
            ['HLF 20', 'HLF 20 / G1', 'HLF 20 / G2', 'HLF 20 / G3', 'HLF 20 / G4', 'HLF 20 / Fahrerhaus', 'LF 10', 'LF 10 / G1', 'LF 10 / G3',
                'LF 10 / Fahrerhaus', 'MTW', 'MTW / Fahrerhaus', 'Lager Gerätehaus', 'Kleiderkammer', 'Atemschutzwerkstatt'],
            TestCityData::locationPaths(),
        );
        // Ganzes Fahrzeug, ohne Fach (Standortwahl „Ganzes Fahrzeug, ohne Fach“).
        $wholeVehicle = array_filter($devices, static fn (SeedDevice $d): bool => in_array($d->location, ['HLF 20', 'LF 10', 'MTW'], true));
        self::assertGreaterThanOrEqual(3, count($wholeVehicle));
        // Alle Start-Gerätetypen und alle Status außer ausgesondert kommen vor.
        self::assertSame(DefaultDeviceTypes::NAMES, array_values(array_unique(array_map(static fn (SeedDevice $d): string => $d->deviceType, $devices))));
        $statuses = array_unique(array_map(static fn (SeedDevice $d): string => $d->status->value, $devices));
        sort($statuses);
        self::assertSame(['defective', 'maintenance', 'ready'], $statuses);
        // Scan-Codes: GS1 mit Trennzeichen, Geräte ohne Code.
        self::assertNotEmpty(array_filter($devices, static fn (SeedDevice $d): bool => str_contains((string) $d->scanCode, "\x1D")));
        self::assertNotEmpty(array_filter($devices, static fn (SeedDevice $d): bool => $d->scanCode === null));
    }

    #[DataProvider('variants')]
    public function testValuesAreValidAndUnique(?int $count): void
    {
        $devices = TestCityData::devices(self::TODAY, $count);
        $paths = TestCityData::locationPaths();

        self::assertCount($count ?? 60, $devices);
        $identifiers = [];
        $scanCodes = [];
        foreach ($devices as $device) {
            self::assertSame($device->identifier, DeviceIdentifier::fromString($device->identifier)->value);
            self::assertSame($device->scanCode, ScanCode::fromString($device->scanCode)?->value);
            self::assertContains($device->location, $paths);
            self::assertContains($device->deviceType, DefaultDeviceTypes::NAMES);
            self::assertNotSame(DeviceStatus::Retired, $device->status);
            foreach ([$device->procuredOn, $device->nextInspectionDue] as $date) {
                if ($date !== null) {
                    DeviceDate::fromString($date, 'Datum');
                }
            }
            self::assertSame($device->note, DeviceText::field($device->note, DeviceField::Note));
            $identifiers[] = mb_strtolower($device->identifier);
            if ($device->scanCode !== null) {
                $scanCodes[] = $device->scanCode;
            }
        }
        self::assertSame($identifiers, array_values(array_unique($identifiers)));
        self::assertSame($scanCodes, array_values(array_unique($scanCodes)));
    }

    public function testNumberedIdentifiers(): void
    {
        $devices = TestCityData::devices(self::TODAY, 2500);

        self::assertSame('T0001', $devices[0]->identifier);
        self::assertSame('T2500', $devices[2499]->identifier);
    }

    #[DataProvider('variants')]
    public function testInspectionDatesCoverAllSections(?int $count): void
    {
        if ($count === 1) {
            self::assertNotNull(TestCityData::devices(self::TODAY, 1)[0]->nextInspectionDue);

            return;
        }
        $sections = [];
        foreach (TestCityData::devices(self::TODAY, $count) as $device) {
            $due = $device->nextInspectionDue;
            $sections[match (true) {
                $due === null => 'ohne',
                $due < self::TODAY => 'überfällig',
                $due <= LocalDate::addDays(self::TODAY, 30) => 'bald',
                default => 'später',
            }] = true;
        }
        ksort($sections);

        self::assertSame(['bald', 'ohne', 'später', 'überfällig'], array_keys($sections));
    }

    #[DataProvider('variants')]
    public function testStepsChangeSomethingAndRetireOnce(?int $count): void
    {
        $devices = TestCityData::devices(self::TODAY, $count);
        $steps = TestCityData::steps($devices, $count !== null);

        $state = [];
        foreach ($devices as $device) {
            $state[$device->identifier] = [$device->location, $device->status];
        }
        $retired = [];
        $perDevice = [];
        foreach ($steps as $step) {
            self::assertArrayHasKey($step->identifier, $state);
            self::assertNotContains($step->identifier, $retired, 'Nach dem Aussondern keine Schritte mehr.');
            $perDevice[$step->identifier] = ($perDevice[$step->identifier] ?? 0) + 1;
            if ($step->retire) {
                $retired[] = $step->identifier;
                continue;
            }
            [$location, $status] = $state[$step->identifier];
            $next = [$step->location ?? $location, $step->status ?? $status];
            self::assertNotSame([$location, $status], $next, 'Jeder Schritt ändert etwas: ' . $step->comment);
            self::assertContains($next[0], TestCityData::locationPaths());
            $state[$step->identifier] = $next;
        }

        if ($count === 1) {
            self::assertCount(2, $steps);
            self::assertSame([], $retired);

            return;
        }
        self::assertCount(1, $retired);
        foreach ($perDevice as $identifier => $number) {
            self::assertGreaterThanOrEqual(2, $number, $identifier);
            self::assertLessThanOrEqual(3, $number, $identifier);
        }
    }

    public function testCountOutOfRangeIsRejected(): void
    {
        foreach ([0, TestCityData::MAX_DEVICES + 1] as $count) {
            try {
                TestCityData::devices(self::TODAY, $count);
                self::fail('Erwartet: Ausnahme für ' . $count);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('zwischen 1 und 5000', $e->getMessage());
            }
        }
    }
}
