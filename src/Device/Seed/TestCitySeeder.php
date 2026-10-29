<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceIdentifier;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStateChange;
use FirefightingEquipment\Device\DeviceText;
use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Device\LocationName;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\NewDevice;
use FirefightingEquipment\Device\ScanCode;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Schreibt Testdaten über die normalen Repositorys (Ereignisse, Versionen, Sperren wie im
 * Betrieb): Standorte, Geräte (Ereignis created), dann der Verlauf (changeState bzw. retire). Jeder
 * Schritt ist eine eigene Transaktion; bricht etwas ab, bleibt Angelegtes stehen (aufräumen mit
 * bin/tenant.php purge-device-data). Keine Mails (die schicken nur die Handler).
 */
final readonly class TestCitySeeder
{
    /** Kommentar am Ereignis „Angelegt“, damit Testdaten in der Historie erkennbar sind. */
    public const string CREATED_COMMENT = 'Testdaten';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * @param int                 $adminId   legt Standorte und Geräte an und sondert aus
     * @param non-empty-list<int> $bookerIds buchen den Verlauf reihum
     * @param list<SeedDevice>    $devices
     * @param list<SeedStep>      $steps
     *
     * @throws TestCitySeedException Gerätetyp fehlt oder ist deaktiviert
     * @throws DeviceException
     */
    public function seed(Tenant $tenant, int $adminId, array $bookerIds, array $devices, array $steps): TestCitySeedResult
    {
        $typeIds = $this->typeIds($tenant);
        $locationIds = $this->createLocations($tenant);

        $repository = new DeviceRepository($this->pdo);
        $created = [];
        foreach ($devices as $device) {
            $typeId = $typeIds[$device->deviceType]
                ?? throw new TestCitySeedException(sprintf('Der Gerätetyp „%s“ fehlt oder ist deaktiviert.', $device->deviceType));
            $created[$device->identifier] = $repository->create($tenant, $adminId, new NewDevice(
                DeviceIdentifier::fromString($device->identifier),
                $typeId,
                $locationIds[$device->location] ?? throw new \LogicException('Unbekannter Standort: ' . $device->location),
                $device->status,
                DeviceText::field($device->serialNumber, DeviceField::SerialNumber),
                DeviceText::field($device->manufacturer, DeviceField::Manufacturer),
                DeviceText::field($device->model, DeviceField::Model),
                $device->procuredOn === null ? null : DeviceDate::fromString($device->procuredOn, 'Das Beschaffungsdatum'),
                $device->nextInspectionDue === null ? null : DeviceDate::fromString($device->nextInspectionDue, 'Das Prüfdatum'),
                DeviceText::field($device->note, DeviceField::Note),
                ScanCode::fromString($device->scanCode),
            ), self::CREATED_COMMENT);
        }

        $retired = 0;
        foreach ($steps as $index => $step) {
            $current = $created[$step->identifier] ?? throw new \LogicException('Unbekanntes Gerät: ' . $step->identifier);
            if ($step->retire) {
                $created[$step->identifier] = $repository->retire($tenant, $adminId, $current->id, $current->version, $step->comment);
                $retired++;
                continue;
            }
            $locationId = $step->location === null
                ? null
                : ($locationIds[$step->location] ?? throw new \LogicException('Unbekannter Standort: ' . $step->location));
            $created[$step->identifier] = $repository->changeState(
                $tenant,
                $bookerIds[$index % count($bookerIds)],
                $current->id,
                $current->version,
                new DeviceStateChange($locationId, $step->status),
                $step->comment,
            );
        }

        return new TestCitySeedResult(count($locationIds), count($created), count($steps), $retired);
    }

    /**
     * @return array<string, int> aktive Gerätetypen nach Name
     */
    private function typeIds(Tenant $tenant): array
    {
        $ids = [];
        foreach (new DeviceTypeRepository($this->pdo)->listByTenant($tenant, false) as $type) {
            $ids[$type->name->value] = $type->id;
        }

        return $ids;
    }

    /**
     * @return array<string, int> Standort-IDs nach Pfad (TestCityData::locationPaths)
     *
     * @throws DeviceException
     */
    private function createLocations(Tenant $tenant): array
    {
        $locations = new LocationRepository($this->pdo);
        $ids = [];
        foreach (TestCityData::LOCATIONS as $name => [$kind, $children]) {
            $parent = $locations->create($tenant, LocationName::fromString($name), $kind, null);
            $ids[$name] = $parent->id;
            foreach ($children as $child) {
                $ids[$name . ' / ' . $child] = $locations->create($tenant, LocationName::fromString($child), null, $parent->id)->id;
            }
        }

        return $ids;
    }
}
