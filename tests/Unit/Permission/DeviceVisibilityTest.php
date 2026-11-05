<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Permission;

use FirefightingEquipment\Device\DeviceEventLocation;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Permission\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceVisibility::class)]
#[CoversClass(DeviceEventLocation::class)]
#[UsesClass(Permissions::class)]
final class DeviceVisibilityTest extends TestCase
{
    public function testAllSeesEverythingWithoutSql(): void
    {
        $all = DeviceVisibility::all();

        self::assertTrue($all->isAll());
        self::assertTrue($all->allows(1, 2));
        self::assertSame(['', []], $all->sql('d'));
        self::assertSame(['', []], $all->locationSql('l.id'));
        self::assertSame(['locations' => null, 'device_types' => null], $all->toArray());
    }

    public function testBothFiltersAreCombinedWithAnd(): void
    {
        $visibility = DeviceVisibility::restricted([5, 3, 5], [7]);

        self::assertFalse($visibility->isAll());
        self::assertTrue($visibility->allows(7, 3));
        self::assertFalse($visibility->allows(8, 3), 'anderer Typ');
        self::assertFalse($visibility->allows(7, 4), 'anderer Standort');
        self::assertSame([' AND d.device_type_id IN (?) AND d.location_id IN (?, ?)', [7, 3, 5]], $visibility->sql('d'));
        self::assertSame(['locations' => [3, 5], 'device_types' => [7]], $visibility->toArray());
    }

    public function testEachFilterAlone(): void
    {
        $locations = DeviceVisibility::restricted([3], null);
        self::assertTrue($locations->allows(99, 3));
        self::assertTrue($locations->filtersLocations());
        self::assertFalse($locations->filtersDeviceTypes());
        self::assertSame([' AND d.location_id IN (?)', [3]], $locations->sql('d'));

        $types = DeviceVisibility::restricted(null, [7]);
        self::assertTrue($types->allows(7, 99));
        self::assertSame([' AND d.device_type_id IN (?)', [7]], $types->sql('d'));
        self::assertSame([' AND t.id IN (?)', [7]], $types->deviceTypeSql('t.id'));
        self::assertSame(['', []], $types->locationSql('l.id'));
    }

    public function testFilterWithoutSelectionSeesNothing(): void
    {
        $visibility = DeviceVisibility::restricted([], null);

        self::assertFalse($visibility->allowsLocation(1));
        self::assertTrue($visibility->allowsDeviceType(1));
        self::assertSame([' AND 0 = 1', []], $visibility->sql('d'));
        self::assertSame([' AND 0 = 1', []], DeviceVisibility::restricted(null, [])->deviceTypeSql('t.id'));
    }

    public function testRejectsUnsafeAliases(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeviceVisibility::all()->sql('d; DROP TABLE x');
    }

    public function testLocationsOutsideAreMasked(): void
    {
        $visibility = DeviceVisibility::restricted([3], null);
        $inside = new DeviceEventLocation(3, 'G1', 'HLF 20');
        $outside = new DeviceEventLocation(4, 'Lager', null);

        self::assertSame($inside, $inside->maskedFor($visibility));
        $masked = $outside->maskedFor($visibility);
        self::assertTrue($masked->isMasked());
        self::assertSame([null, 'anderer Standort', 'anderer Standort'], [$masked->id, $masked->name, $masked->path()]);
        self::assertSame($outside, $outside->maskedFor(DeviceVisibility::all()));
    }

    /**
     * F3: Mit Datenfilter wirken Benutzer, Standorte und Gerätetypen Ändern sowie der CSV-Import nicht.
     */
    public function testRolesWithFilterHaveNoAdministration(): void
    {
        $rows = Permissions::fromRows(
            ['users' => 'write', 'locations' => 'write', 'device_types' => 'write', 'devices' => 'write', 'settings' => 'write'],
            ['csv.import', 'device.edit'],
        );
        $filtered = $rows->ofRole(1, 1, DeviceVisibility::restricted([3], null));

        self::assertNull($filtered->level(Area::Users));
        self::assertSame(AreaLevel::Read, $filtered->level(Area::Locations));
        self::assertSame(AreaLevel::Read, $filtered->level(Area::DeviceTypes));
        self::assertSame(AreaLevel::Write, $filtered->level(Area::Devices));
        self::assertSame(AreaLevel::Write, $filtered->level(Area::Settings));
        self::assertFalse($filtered->can(Action::CsvImport));
        self::assertTrue($filtered->can(Action::DeviceEdit));
        self::assertTrue($filtered->isFiltered());

        $unfiltered = $rows->ofRole(1, 1, DeviceVisibility::all());
        self::assertSame(AreaLevel::Write, $unfiltered->level(Area::Users));
        self::assertTrue($unfiltered->can(Action::CsvImport));
        self::assertFalse($unfiltered->isFiltered());
    }

    public function testAdminRoleNeverHasAFilter(): void
    {
        $this->expectException(\LogicException::class);
        Permissions::all()->ofRole(1, 1, DeviceVisibility::restricted([3], null));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function repositories(): iterable
    {
        foreach ([DeviceRepository::class, DeviceEventRepository::class, DeviceNoticeRepository::class, LocationRepository::class, DeviceTypeRepository::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * Jedes Repository, das Geräte, Ereignisse, Hinweise oder Zahlen dazu liest, verlangt den
     * Datenfilter im Konstruktor, ohne Standardwert (Plan Phase 1, Abschnitt 4).
     *
     * @param class-string $class
     */
    #[DataProvider('repositories')]
    public function testRepositoriesRequireAVisibility(string $class): void
    {
        $constructor = new \ReflectionClass($class)->getConstructor();
        self::assertNotNull($constructor);
        $parameters = array_values(array_filter(
            $constructor->getParameters(),
            static fn (\ReflectionParameter $p): bool => (string) $p->getType() === DeviceVisibility::class,
        ));

        self::assertCount(1, $parameters, $class);
        self::assertFalse($parameters[0]->isOptional(), $class . ': ohne Standardwert');
        self::assertFalse($parameters[0]->allowsNull(), $class . ': nicht null');
    }
}
