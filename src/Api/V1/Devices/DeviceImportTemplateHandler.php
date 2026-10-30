<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceTypeRepository;
use FirefightingEquipment\Device\Import\DeviceImportTemplate;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;

/**
 * Hilfen zum CSV-Import (Admin), Format wie der Export (CsvFormat):
 *
 * - GET api/v1/devices/import/template: Vorlage mit den Spalten des Imports und zwei Beispielzeilen
 *   (DeviceImportTemplate), Dateiname geraete-vorlage-<kürzel>.csv.
 * - GET api/v1/devices/import/values: „Gültige Werte“ (aktive Gerätetypen und Standorte, Status,
 *   Formate), Dateiname gueltige-werte-<kürzel>-<JJJJ-MM-TT>.csv.
 *
 * Query-Parameter werden ignoriert.
 */
final readonly class DeviceImportTemplateHandler implements RequestHandler
{
    /**
     * @param bool $validValues true: „Gültige Werte“, false: Vorlage
     */
    public function __construct(private bool $validValues, private Clock $clock)
    {
    }

    public function handle(RequestContext $context): CsvResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $types = new DeviceTypeRepository($pdo)->listByTenant($tenant, false);
        $tree = new LocationRepository($pdo)->tree($tenant, false);
        $today = LocalDate::today($this->clock);
        $identity = static fn (mixed $row): array => $row;

        if ($this->validValues) {
            return new CsvResponse(
                sprintf('gueltige-werte-%s-%s.csv', $tenant->slug->value, $today),
                DeviceImportTemplate::VALUES_HEADER,
                DeviceImportTemplate::validValues($types, $tree),
                $identity,
                $context->errors,
            );
        }

        return new CsvResponse(
            sprintf('geraete-vorlage-%s.csv', $tenant->slug->value),
            DeviceImportTemplate::header(),
            DeviceImportTemplate::exampleRows($types, $tree, $today),
            $identity,
            $context->errors,
        );
    }
}
