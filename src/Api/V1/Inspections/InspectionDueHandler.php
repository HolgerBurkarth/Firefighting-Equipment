<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Inspections;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceFilter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\InspectionSection;
use FirefightingEquipment\Device\InspectionWindow;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;

/**
 * GET api/v1/inspection-due (angemeldet): Übersicht der Prüffristen ohne ausgesonderte Geräte,
 * Antwort {today, days, until, overdue, due_soon[, without_date]}, je Abschnitt {devices, total}.
 *
 * „Heute“ nach deutscher Zeit (InspectionWindow). overdue: Prüfdatum vor heute, am stärksten
 * überfällig zuerst, je Gerät days_overdue (ab 1). due_soon: heute bis heute + days, je Gerät
 * days_until_due (0 = heute). without_date nur mit include_without_date=1, nach Kennung.
 *
 * Parameter (alle optional): days (1–365, Standard 30), include_without_date=1, device_type_id,
 * location_id, include_sublocations=1 (nur mit location_id), limit (1–500 je Abschnitt, Standard
 * 200). Andere Parameter werden ignoriert; unbekannte oder fremde IDs ergeben leere Abschnitte.
 */
final readonly class InspectionDueHandler implements RequestHandler
{
    public const int DEFAULT_LIMIT = 200;
    public const int MAX_LIMIT = 500;

    public function __construct(private Clock $clock = new SystemClock())
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');

        try {
            $filter = new DeviceFilter(
                $context->queryInt('device_type_id', 1, DeviceFields::MAX_ID),
                $context->queryInt('location_id', 1, DeviceFields::MAX_ID),
                $context->queryFlag('include_sublocations'),
            );
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }
        $days = $context->queryInt('days', InspectionWindow::MIN_DAYS, InspectionWindow::MAX_DAYS) ?? InspectionWindow::DEFAULT_DAYS;
        $withoutDate = $context->queryFlag('include_without_date');
        $limit = $context->queryInt('limit', 1, self::MAX_LIMIT) ?? self::DEFAULT_LIMIT;

        $window = InspectionWindow::from($this->clock, $days);
        $devices = new DeviceRepository($context->db->get());
        $sections = [InspectionSection::Overdue, InspectionSection::DueSoon, ...($withoutDate ? [InspectionSection::WithoutDate] : [])];

        $json = ['today' => $window->today, 'days' => $window->days, 'until' => $window->until];
        foreach ($sections as $section) {
            $page = $devices->listInspections($tenant, $filter, $window, $section, $limit);
            $json[$section->value] = [
                'devices' => array_map(static fn (Device $d): array => self::device($d, $window, $section), $page->devices),
                'total' => $page->total,
            ];
        }

        return new JsonResponse($json);
    }

    /**
     * @return array<string, mixed>
     */
    private static function device(Device $device, InspectionWindow $window, InspectionSection $section): array
    {
        // Überfällig und bald fällig enthalten nur Geräte mit Prüfdatum.
        $days = $device->nextInspectionDue === null ? null : $window->daysUntil($device->nextInspectionDue);

        return [...DeviceJson::summary($device), ...match ($section) {
            InspectionSection::Overdue => ['days_overdue' => -($days ?? throw new \LogicException('Prüfdatum fehlt.'))],
            InspectionSection::DueSoon => ['days_until_due' => $days ?? throw new \LogicException('Prüfdatum fehlt.')],
            InspectionSection::WithoutDate => [],
        }];
    }
}
