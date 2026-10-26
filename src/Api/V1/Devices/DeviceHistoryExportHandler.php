<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceDate;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceHistoryFilter;
use FirefightingEquipment\Http\CsvResponse;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;

/**
 * GET api/v1/device-events/export (angemeldet): Historie aller Geräte der Stadt (auch
 * ausgesonderter) als CSV-Download (Spalten siehe DeviceCsv), Dateiname
 * historie-<kürzel>-<von>-bis-<bis>.csv.
 *
 * Parameter (alle optional): from und to (JJJJ-MM-TT, Kalendertage in deutscher Zeit, beide
 * einschließlich; Standard: die letzten DEFAULT_DAYS Tage bis heute, fehlt nur from, endet der
 * Zeitraum DEFAULT_DAYS Tage vorher am Tag to; höchstens MAX_DAYS Tage), device_id, device_type_id
 * (aktueller Typ des Geräts), location_id (Von- oder Nach-Standort) mit include_sublocations=1,
 * order=newest (Standard) oder oldest. Mehr als $maxRows Einträge: 409 rule_violation mit
 * details {events, max}, bevor etwas geschrieben wird.
 */
final readonly class DeviceHistoryExportHandler implements RequestHandler
{
    public const int DEFAULT_DAYS = 90;
    public const int MAX_DAYS = 366;
    public const int MAX_ROWS = 100_000;

    /**
     * @param int $maxRows Obergrenze der Einträge (andere als MAX_ROWS nur für Tests)
     */
    public function __construct(private Clock $clock, private int $maxRows = self::MAX_ROWS)
    {
    }

    public function handle(RequestContext $context): CsvResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $query = $context->request->query();

        try {
            $to = isset($query['to']) ? DeviceDate::fromString($query['to'], 'Der Parameter "to"')->value : LocalDate::today($this->clock);
            $from = isset($query['from'])
                ? DeviceDate::fromString($query['from'], 'Der Parameter "from"')->value
                : LocalDate::addDays($to, 1 - self::DEFAULT_DAYS);
            $days = LocalDate::daysBetween($from, $to) + 1;
            if ($days < 1) {
                throw HttpException::invalidRequest('Der Zeitraum ist ungültig: "from" liegt nach "to".');
            }
            if ($days > self::MAX_DAYS) {
                throw HttpException::invalidRequest(sprintf('Der Zeitraum darf höchstens %d Tage umfassen.', self::MAX_DAYS));
            }
            $filter = new DeviceHistoryFilter(
                LocalDate::startOfDayUtc($from),
                LocalDate::startOfDayUtc(LocalDate::addDays($to, 1)),
                $context->queryInt('device_id', 1, DeviceFields::MAX_ID),
                $context->queryInt('device_type_id', 1, DeviceFields::MAX_ID),
                $context->queryInt('location_id', 1, DeviceFields::MAX_ID),
                $context->queryFlag('include_sublocations'),
                match ($query['order'] ?? 'newest') {
                    'newest' => true,
                    'oldest' => false,
                    default => throw HttpException::invalidRequest('Der Parameter "order" muss "newest" oder "oldest" sein.'),
                },
            );
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        $events = new DeviceEventRepository($context->db->get());
        $count = $events->countHistory($tenant, $filter);
        if ($count > $this->maxRows) {
            throw HttpException::ruleViolation(
                sprintf('Der Zeitraum enthält %d Einträge, höchstens %d sind möglich. Bitte den Zeitraum verkleinern oder filtern.', $count, $this->maxRows),
                ['events' => $count, 'max' => $this->maxRows],
            );
        }

        return new CsvResponse(
            sprintf('historie-%s-%s-bis-%s.csv', $tenant->slug->value, $from, $to),
            DeviceCsv::EVENT_HEADER,
            $events->exportHistory($tenant, $filter),
            DeviceCsv::event(...),
            $context->errors,
        );
    }
}
