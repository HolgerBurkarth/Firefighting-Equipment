<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Loans;

use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\Loan\LoanRepository;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Device\LocationTaskRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Time\Clock;
use FirefightingEquipment\Time\LocalDate;
use FirefightingEquipment\Time\SystemClock;

/**
 * GET api/v1/loans (Bereich `loans` Ansehen, Phase 2, P2.5): die offenen Verleihe.
 * Antwort {today, summary {open, overdue}, shown, loans [{id, device (wie GET devices), borrower {id, name},
 * contact, due_back_on, overdue, days_overdue, comment, lent_at, lent_by {id, name}}], borrowers [{id, name}]}.
 * `borrowers` sind die aktiven Standorte mit der Aufgabe „Ausgeliehen an“ (Ziele für „Verleihen“),
 * `returns` die mit der Aufgabe „Rückgabe“. Sortiert nach Leihnehmer, dann „Rückgabe bis“; höchstens
 * MAX_SHOWN, `summary` zählt alle. „Überfällig“ = „Rückgabe bis“ vor heute (Europe/Berlin).
 */
final readonly class LoanListHandler implements RequestHandler
{
    public const int MAX_SHOWN = 500;

    public function __construct(private Clock $clock = new SystemClock())
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $loans = new LoanRepository($pdo);
        $devices = new DeviceRepository($pdo, $context->visibility(), null, $context->channel());
        $locations = new LocationRepository($pdo, $context->visibility());
        $today = LocalDate::today($this->clock);

        $items = [];
        foreach ($loans->listOpen($tenant, self::MAX_SHOWN) as $row) {
            $device = $devices->find($tenant, (int) $row['device_id']);
            if ($device === null) {
                continue;
            }
            $due = $row['due_back_on'] === null ? null : (string) $row['due_back_on'];
            $overdue = $due !== null && $due < $today;
            $items[] = [
                'id' => (int) $row['id'],
                'device' => DeviceJson::listItem($device),
                'borrower' => ['id' => (int) $row['borrower_id'], 'name' => (string) $row['borrower']],
                'contact' => $row['contact'] === null ? null : (string) $row['contact'],
                'due_back_on' => $due,
                'overdue' => $overdue,
                'days_overdue' => $overdue ? LocalDate::daysBetween($due, $today) : 0,
                'comment' => $row['comment'] === null ? null : (string) $row['comment'],
                'lent_at' => str_replace(' ', 'T', (string) $row['lent_at']) . 'Z',
                'lent_by' => ['id' => (int) $row['lent_by_id'], 'name' => (string) $row['lent_by_name']],
            ];
        }
        $labels = [];
        foreach (new LocationTaskRepository($pdo)->list($tenant) as $task) {
            $labels[$task['task']->value] = $task['label'];
        }

        return new JsonResponse([
            'today' => $today,
            'summary' => $loans->counts($tenant, $today),
            'shown' => count($items),
            'loans' => $items,
            'borrowers' => $locations->withTask($tenant, LocationTask::Borrower),
            'returns' => $locations->withTask($tenant, LocationTask::Returns),
            'labels' => ['borrower' => $labels[LocationTask::Borrower->value] ?? LocationTask::Borrower->label(), 'returns' => $labels[LocationTask::Returns->value] ?? LocationTask::Returns->label()],
        ]);
    }
}
