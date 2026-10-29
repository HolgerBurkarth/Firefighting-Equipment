<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

use FirefightingEquipment\Device\DefaultDeviceTypes;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\LocationKind;
use FirefightingEquipment\Time\LocalDate;

/**
 * Testdaten der Test-Stadt (bin/seed-test-city.php): feste, wiederholbare Werte ohne Zufall.
 * Prüfdaten richten sich nach „heute“ (überfällig, in 30 Tagen fällig, später, ohne Datum).
 */
final class TestCityData
{
    /** Einzige Stadt, für die es Testdaten gibt (fest, nicht abschaltbar). */
    public const string SLUG = 'test';

    /** Höchstzahl für --devices (Kennungen T0001 … T5000). */
    public const int MAX_DEVICES = 5000;

    public const string WORKSHOP = 'Atemschutzwerkstatt';
    public const string STORE = 'Lager Gerätehaus';

    /**
     * Standorte der Ebene 1 mit Art und Unterstandorten (Fächern).
     *
     * @var array<string, array{LocationKind, list<string>}>
     */
    public const array LOCATIONS = [
        'HLF 20' => [LocationKind::Vehicle, ['G1', 'G2', 'G3', 'G4', 'Fahrerhaus']],
        'LF 10' => [LocationKind::Vehicle, ['G1', 'G3', 'Fahrerhaus']],
        'MTW' => [LocationKind::Vehicle, ['Fahrerhaus']],
        self::STORE => [LocationKind::Room, []],
        'Kleiderkammer' => [LocationKind::Room, []],
        self::WORKSHOP => [LocationKind::External, []],
    ];

    /** Hersteller und Modell je Start-Gerätetyp. */
    private const array PRODUCTS = [
        'Atemschutzmaske' => ['Dräger', 'FPS 7000'],
        'Atemschutzgerät' => ['Dräger', 'PSS 7000'],
        'Funkgerät' => ['Motorola', 'MXP600'],
        'Wärmebildkamera' => ['MSA', 'Evolution 6000'],
    ];

    /** GS1: Anwendungskennung 01 (GTIN) + GTIN-14, danach GS und 21 (Seriennummer). */
    private const string GS1_PREFIX = '0104048675000017';

    private function __construct()
    {
    }

    /**
     * Pfade aller Standorte wie in der App („HLF 20“, „HLF 20 / G1“ …), Ebene 1 vor ihren Fächern.
     *
     * @return list<string>
     */
    public static function locationPaths(): array
    {
        $paths = [];
        foreach (self::LOCATIONS as $name => [, $children]) {
            $paths[] = $name;
            foreach ($children as $child) {
                $paths[] = $name . ' / ' . $child;
            }
        }

        return $paths;
    }

    /**
     * Geräte: ohne $count die ausgesuchten rund 60 (Masken, Atemschutzgeräte, Funkgeräte,
     * Wärmebildkameras), sonst $count Geräte T0001 … (für die Suche auf dem Server).
     *
     * @param string $today JJJJ-MM-TT (Europe/Berlin)
     *
     * @return list<SeedDevice>
     */
    public static function devices(string $today, ?int $count = null): array
    {
        return $count === null ? self::selectedDevices($today) : self::numberedDevices($today, $count);
    }

    /**
     * Verlauf: einige Geräte mit 2–3 Umbuchungen bzw. Statuswechseln, eines wird ausgesondert.
     *
     * @param list<SeedDevice> $devices aus devices()
     *
     * @return list<SeedStep>
     */
    public static function steps(array $devices, bool $numbered): array
    {
        return $numbered ? self::numberedSteps($devices) : self::selectedSteps();
    }

    /**
     * @return list<SeedDevice>
     */
    private static function selectedDevices(string $today): array
    {
        $devices = [];
        $i = 0;
        for ($n = 1; $n <= 24; $n++) {
            $location = match (true) {
                $n <= 6 => 'HLF 20 / G1',
                $n <= 9 => 'HLF 20 / G2',
                $n <= 13 => 'LF 10 / G1',
                $n <= 21 => self::STORE,
                default => self::WORKSHOP,
            };
            $status = match (true) {
                $n === 17 => DeviceStatus::Defective,
                $n >= 22 => DeviceStatus::Maintenance,
                default => DeviceStatus::Ready,
            };
            $serial = sprintf('ARFK-%04d', 1000 + $n);
            // Masken ab M19 ohne Barcode; sonst steht die Seriennummer im Code.
            $devices[] = self::device('M' . $n, 'Atemschutzmaske', $location, $status, $n <= 18 ? $serial : null, $serial, $today, $i++, match ($n) {
                3 => 'Ersatzmaske, Größe M',
                17 => 'Sichtscheibe zerkratzt',
                default => null,
            });
        }
        for ($n = 1; $n <= 12; $n++) {
            $location = match (true) {
                $n <= 4 => 'HLF 20 / G3',
                $n <= 6 => 'HLF 20 / G4',
                $n <= 9 => 'LF 10 / G3',
                default => self::WORKSHOP,
            };
            $status = match (true) {
                $n === 11 => DeviceStatus::Defective,
                $n >= 10 => DeviceStatus::Maintenance,
                default => DeviceStatus::Ready,
            };
            $serial = sprintf('ARHL-%04d', 2000 + $n);
            // GS1-DataMatrix mit Trennzeichen GS (0x1D) vor der Seriennummer.
            $devices[] = self::device('PA ' . $n, 'Atemschutzgerät', $location, $status, self::GS1_PREFIX . "\x1D21" . $serial, $serial, $today, $i++);
        }
        for ($n = 1; $n <= 20; $n++) {
            $location = match (true) {
                $n <= 4 => 'HLF 20 / Fahrerhaus',
                $n <= 7 => 'LF 10 / Fahrerhaus',
                $n <= 10 => 'MTW / Fahrerhaus',
                $n <= 12 => 'MTW',
                default => 'Kleiderkammer',
            };
            $status = $n === 15 ? DeviceStatus::Defective : DeviceStatus::Ready;
            // Strichcode wie EAN-13 (13 Ziffern); HRT 20 ohne Code.
            $scanCode = $n < 20 ? sprintf('40123450%05d', $n) : null;
            $devices[] = self::device('HRT ' . $n, 'Funkgerät', $location, $status, $scanCode, sprintf('752IXT%04d', $n), $today, $i++, $n === 13 ? 'Ladegerät fehlt' : null);
        }
        foreach (['HLF 20', 'LF 10', self::STORE, self::WORKSHOP] as $index => $location) {
            $n = $index + 1;
            $status = $location === self::WORKSHOP ? DeviceStatus::Maintenance : DeviceStatus::Ready;
            $devices[] = self::device('WBK ' . $n, 'Wärmebildkamera', $location, $status, sprintf('WBK-E6K-%05d', 31000 + $n), sprintf('E6K%05d', 31000 + $n), $today, $i++);
        }

        return $devices;
    }

    /**
     * @return list<SeedDevice>
     */
    private static function numberedDevices(string $today, int $count): array
    {
        if ($count < 1 || $count > self::MAX_DEVICES) {
            throw new \InvalidArgumentException(sprintf('Anzahl der Geräte muss zwischen 1 und %d liegen.', self::MAX_DEVICES));
        }
        $paths = self::locationPaths();
        $devices = [];
        for ($n = 1; $n <= $count; $n++) {
            $identifier = sprintf('T%04d', $n);
            $serial = 'SN-' . $identifier;
            $status = match (true) {
                $n % 13 === 0 => DeviceStatus::Defective,
                $n % 7 === 0 => DeviceStatus::Maintenance,
                default => DeviceStatus::Ready,
            };
            $scanCode = match ($n % 3) {
                0 => null,
                1 => 'TS-' . $identifier,
                default => self::GS1_PREFIX . "\x1D21" . $serial,
            };
            $devices[] = self::device(
                $identifier,
                DefaultDeviceTypes::NAMES[$n % count(DefaultDeviceTypes::NAMES)],
                $paths[$n % count($paths)],
                $status,
                $scanCode,
                $serial,
                $today,
                $n - 1,
            );
        }

        return $devices;
    }

    private static function device(
        string $identifier,
        string $type,
        string $location,
        DeviceStatus $status,
        ?string $scanCode,
        string $serial,
        string $today,
        int $index,
        ?string $note = null,
    ): SeedDevice {
        [$manufacturer, $model] = self::PRODUCTS[$type];

        return new SeedDevice(
            $identifier,
            $type,
            $location,
            $status,
            $scanCode,
            $serial,
            $manufacturer,
            $model,
            sprintf('%d-%02d-15', 2015 + $index % 10, 1 + $index % 12),
            self::due($today, $index),
            $note,
        );
    }

    /**
     * Prüfdatum je Gerät reihum: überfällig, in 30 Tagen fällig, dreimal später, ohne Datum.
     */
    private static function due(string $today, int $index): ?string
    {
        return match ($index % 6) {
            0 => LocalDate::addDays($today, -(3 + $index % 40)),
            1 => LocalDate::addDays($today, $index % 31),
            5 => null,
            default => LocalDate::addDays($today, 60 + ($index * 7) % 340),
        };
    }

    /**
     * @return list<SeedStep>
     */
    private static function selectedSteps(): array
    {
        return [
            new SeedStep('M2', self::WORKSHOP, DeviceStatus::Maintenance, 'Nach dem Einsatz zur Reinigung'),
            new SeedStep('M2', 'HLF 20 / G1', DeviceStatus::Ready, 'Gereinigt und geprüft'),
            new SeedStep('PA 3', null, DeviceStatus::Defective, 'Lungenautomat bläst ab'),
            new SeedStep('PA 3', self::WORKSHOP, DeviceStatus::Maintenance, 'Zur Reparatur gebracht'),
            new SeedStep('PA 3', 'HLF 20 / G3', DeviceStatus::Ready, 'Repariert zurück'),
            new SeedStep('HRT 2', 'MTW / Fahrerhaus', null, 'Für die Übung umgeladen'),
            new SeedStep('HRT 2', 'HLF 20 / Fahrerhaus', null, 'Nach der Übung zurück'),
            new SeedStep('WBK 1', 'LF 10', null, 'Getauscht, HLF in der Werkstatt'),
            new SeedStep('WBK 1', 'HLF 20', null, 'Zurückgetauscht'),
            new SeedStep('M10', null, DeviceStatus::Maintenance, 'Ventilscheibe prüfen'),
            new SeedStep('M10', null, DeviceStatus::Ready, 'Ventilscheibe in Ordnung'),
            new SeedStep('HRT 16', 'LF 10 / Fahrerhaus', null, 'Ersatz für HRT 5'),
            new SeedStep('HRT 16', 'Kleiderkammer', null, 'Zurück ins Lager'),
            new SeedStep('M24', null, DeviceStatus::Defective, 'Sichtscheibe gebrochen'),
            SeedStep::retire('M24', 'Nicht reparierbar, ausgesondert'),
        ];
    }

    /**
     * Die ersten sechs Geräte in die Werkstatt (bzw. ins Lager) und zurück, das siebte defekt und
     * ausgesondert (bei weniger Geräten entsprechend weniger).
     *
     * @param list<SeedDevice> $devices
     *
     * @return list<SeedStep>
     */
    private static function numberedSteps(array $devices): array
    {
        $steps = [];
        foreach (array_slice($devices, 0, 6) as $device) {
            $away = $device->location === self::WORKSHOP ? self::STORE : self::WORKSHOP;
            $status = $device->status === DeviceStatus::Maintenance ? DeviceStatus::Defective : DeviceStatus::Maintenance;
            $steps[] = new SeedStep($device->identifier, $away, $status, 'Zur Prüfung');
            $steps[] = new SeedStep($device->identifier, $device->location, $device->status, 'Geprüft zurück');
        }
        $retired = $devices[6] ?? null;
        if ($retired !== null) {
            if ($retired->status !== DeviceStatus::Defective) {
                $steps[] = new SeedStep($retired->identifier, null, DeviceStatus::Defective, 'Gehäuse gebrochen');
            }
            $steps[] = SeedStep::retire($retired->identifier, 'Nicht reparierbar, ausgesondert');
        }

        return $steps;
    }
}
