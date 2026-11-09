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

    public function testNoneSeesNothing(): void
    {
        $none = DeviceVisibility::none();

        self::assertFalse($none->isAll());
        self::assertFalse($none->allows(1, 2));
        self::assertSame([' AND 0 = 1 AND 0 = 1', []], $none->sql('d'));
        self::assertSame(['locations' => [], 'device_types' => []], $none->toArray());
    }

    /** Ohne Rolle keine Rechte und auch keine Sichtbarkeit (nicht „alle“). */
    public function testPermissionsWithoutRoleSeeNothing(): void
    {
        $none = Permissions::none();

        self::assertSame(DeviceVisibility::none()->toArray(), $none->visibility()->toArray());
        self::assertSame([], $none->areas());
        self::assertSame([], $none->actions());
    }

    /**
     * Wo der Code ausdrücklich alle Geräte sieht (DeviceVisibility::all()): feste Liste mit Grund.
     * Eine neue Stelle lässt den Test scheitern; sie kommt nur nach Prüfung dazu, dass dort kein
     * Konto mit Datenfilter Daten sieht (Plan Phase 1, Abschnitt 4).
     */
    public function testAllIsUsedOnlyAtKnownPlaces(): void
    {
        self::assertSame([
            // Name der Sammelstelle für „Benutzt melden“ (der Ablauf gibt das Ziel vor, G4); keine Geräte.
            'src/Api/V1/Offline/ClientConfigHandler.php' => 1,
            // Super-Admin: Standorte und Typen als Auswahl für „Sieht nur …“ (ohne Geräte).
            // Rückgabe ohne Anmeldung (P2.6): der Schlüssel des Aushangs gibt das Ziel vor, es gibt kein Konto und keinen Filter.
            'src/Api/V1/Returns/PublicReturnHandler.php' => 1,
            'src/Api/V1/Roles/RoleHandler.php' => 2,
            // Antwort auf eine Buchung durch einen Ablauf (Benutzt melden, G4): das Gerät darf den Filter verlassen haben.
            'src/Device/DeviceRepository.php' => 2, // Benutzt melden (G4) und Tausch (P2.8): Ziel/Antwort außerhalb des Filters
            // CSV-Import: nur Rollen ohne Datenfilter (F3).
            'src/Device/Import/DeviceImportPlanner.php' => 3,
            // Testdaten der Test-Stadt (Kommandozeile).
            'src/Device/Seed/TestCitySeeder.php' => 3,
            // Mails „Log“ und Hinweise: liest alles, maskiert je Empfänger.
            'src/Notification/DeviceLogNotifier.php' => 3,
            // Rolle Admin und Rollen ohne Datenfilter.
            'src/Permission/PermissionRepository.php' => 2,
            // Permissions::all() (Admin) und of() (Filter danach per ofRole()).
            'src/Permission/Permissions.php' => 2,
        ], self::usesOfAll());
    }

    /**
     * Aufrufe von DeviceVisibility::all() im PHP-Code der Anwendung (ohne Kommentare).
     *
     * @return array<string, int> Pfad relativ zum Projekt → Anzahl, nach Pfad sortiert
     */
    private static function usesOfAll(): array
    {
        $root = dirname(__DIR__, 3);
        $uses = [];
        foreach (['src', 'bin', 'public', 'scripts'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                self::assertIsString($code);
                $tokens = array_values(array_filter(
                    \PhpToken::tokenize($code),
                    static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
                ));
                $count = 0;
                foreach ($tokens as $i => $token) {
                    $class = ltrim($token->text, '\\');
                    if (($class === 'DeviceVisibility' || str_ends_with($class, '\\DeviceVisibility'))
                        && ($tokens[$i + 1] ?? null)?->is(T_DOUBLE_COLON)
                        && strtolower($tokens[$i + 2]->text ?? '') === 'all') {
                        ++$count;
                    }
                }
                if ($count > 0) {
                    $uses[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $count;
                }
            }
        }
        ksort($uses);

        return $uses;
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
