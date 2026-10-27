<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;

/**
 * GET api/v1/devices/export (angemeldet): Geräteliste als CSV-Download (Spalten siehe DeviceCsv),
 * Dateiname geraete-<kürzel>-<JJJJ-MM-TT>.csv (heute in deutscher Zeit).
 *
 * Filter und Sortierung wie GET devices (device_type_id, location_id, include_sublocations,
 * status, q, include_retired, sort, dir; Standard natürlich nach Kennung), ohne
 * Seitenbegrenzung; limit und offset werden wie andere unbekannte Parameter ignoriert. Ungültige
 * Werte 400 (als JSON).
 */
final readonly class DeviceExportHandler implements RequestHandler
{
    public function __construct(private Clock $clock)
    {
    }

    public function handle(RequestContext $context): CsvResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $filter = DeviceListHandler::filter($context);
        [$sort, $direction] = DeviceListHandler::sort($context);

        return new CsvResponse(
            sprintf('geraete-%s-%s.csv', $tenant->slug->value, LocalDate::today($this->clock)),
            DeviceCsv::DEVICE_HEADER,
            new DeviceRepository($context->db->get())->export($tenant, $filter, $sort, $direction),
            DeviceCsv::device(...),
            $context->errors,
        );
    }
}
