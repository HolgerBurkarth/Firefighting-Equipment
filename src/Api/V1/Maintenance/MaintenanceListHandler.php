<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Maintenance;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceJson;
use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\DeviceFilter;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Device\DeviceSort;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Device\LocationTaskRepository;
use FirefightingEquipment\Device\SortDirection;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\Tenant;

/**
 * GET api/v1/maintenance (Bereich `maintenance` Ansehen, Phase 2, P2.4): die Stufen der Wartung.
 * Antwort {"collection": Stufe, "workshop": Stufe, "default_store": {id, name}|null}; je Stufe
 * {label, locations [{id, name}], total, shown, devices [Eintrag wie GET devices]}: die nicht
 * ausgesonderten Geräte an den aktiven Standorten mit der Aufgabe Sammelstelle bzw. Werkstatt (samt
 * Fächern), am längsten wartende zuerst (`state_changed_at`, G5: kein Zwischenspeicher; bei gleicher Zeit natürlich nach Nummer). Höchstens
 * MAX_SHOWN je Stufe; `total` zählt alle. Ohne einen solchen Standort ist die Stufe leer. Das Konto mit
 * dem Bereich hat nie einen Datenfilter (F3).
 */
final readonly class MaintenanceListHandler implements RequestHandler
{
    /** Höchstzahl der Geräte je Stufe in der Antwort. */
    public const int MAX_SHOWN = 200;

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $devices = new DeviceRepository($pdo, $context->visibility(), null, $context->channel());
        $locations = new LocationRepository($pdo, $context->visibility());
        $labels = [];
        foreach (new LocationTaskRepository($pdo)->list($tenant) as $task) {
            $labels[$task['task']->value] = $task['label'];
        }

        try {
            $collection = self::stage($tenant, $devices, $locations, LocationTask::Collection, $labels);
            $workshop = self::stage($tenant, $devices, $locations, LocationTask::Workshop, $labels);
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }
        $store = $locations->withTask($tenant, LocationTask::Store, defaultStoreOnly: true)[0] ?? null;

        return new JsonResponse(['collection' => $collection, 'workshop' => $workshop, 'default_store' => $store]);
    }

    /**
     * @param array<string, string> $labels Anzeigenamen der Aufgaben
     *
     * @return array<string, mixed>
     *
     * @throws DeviceException
     */
    private static function stage(Tenant $tenant, DeviceRepository $devices, LocationRepository $locations, LocationTask $task, array $labels): array
    {
        $places = $locations->withTask($tenant, $task);
        $found = [];
        $total = 0;
        foreach ($places as $place) {
            $page = $devices->list($tenant, new DeviceFilter(locationId: $place['id'], includeSublocations: true), DeviceSort::Identifier, SortDirection::Asc, self::MAX_SHOWN, 0);
            $total += $page->total;
            array_push($found, ...$page->devices);
        }
        usort($found, static fn (Device $a, Device $b): int => ($a->stateChangedAt ?? $a->updatedAt) <=> ($b->stateChangedAt ?? $b->updatedAt) ?: strnatcasecmp($a->identifier->value, $b->identifier->value) ?: $a->id <=> $b->id);
        $shown = array_slice($found, 0, self::MAX_SHOWN);

        return [
            'label' => $labels[$task->value] ?? $task->label(),
            'locations' => $places,
            'total' => $total,
            'shown' => count($shown),
            'devices' => array_map(DeviceJson::listItem(...), $shown),
        ];
    }
}
