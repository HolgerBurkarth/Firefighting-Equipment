<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Devices;

use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\InspectionWindow;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\SystemClock;
use FirefightingEquipment\User\UserRole;

/**
 * GET api/v1/device-summary (angemeldet): Zahlen für die Startseite ohne ausgesonderte Geräte,
 * Antwort {today, devices {total, by_status [{status, status_label, count}]}, inspection {overdue,
 * due_soon, due_soon_days, without_date}}. Fenster wie bei inspection-due mit Standard 30 Tage;
 * keine Parameter, eine Abfrage. Nur für Admins zusätzlich notices {open} (offene Hinweise, zweite
 * Abfrage); bei user fehlt das Feld.
 */
final readonly class DeviceSummaryHandler implements RequestHandler
{
    public function __construct(private Clock $clock = new SystemClock())
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');

        $summary = new DeviceRepository($context->db->get())->summary($tenant, InspectionWindow::from($this->clock));
        $byStatus = [];
        foreach ($summary->byStatus as $status => $count) {
            $byStatus[] = ['status' => $status, 'status_label' => DeviceStatus::from($status)->label(), 'count' => $count];
        }

        $body = [
            'today' => $summary->window->today,
            'devices' => ['total' => $summary->total(), 'by_status' => $byStatus],
            'inspection' => [
                'overdue' => $summary->overdue,
                'due_soon' => $summary->dueSoon,
                'due_soon_days' => $summary->window->days,
                'without_date' => $summary->withoutDate,
            ],
        ];
        if ($context->auth()->user->role === UserRole::Admin) {
            $body['notices'] = ['open' => new DeviceNoticeRepository($context->db->get())->countOpen($tenant)];
        }

        return new JsonResponse($body);
    }
}
