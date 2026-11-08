<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\LocationTask;
use FirefightingEquipment\Device\LocationTaskRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantRepository;

/**
 * Aufgaben von Standorten je Stadt (Phase 2, P2.2). Antwort immer {"tasks": [{task, label,
 * default_label, enabled, locations}]} in der Reihenfolge des Katalogs.
 *
 * - GET api/v1/location-tasks (unter einer Stadt, Rechte wie die Standorte): die Aufgaben der Stadt;
 *   der Admin wählt daraus nur freigeschaltete (enabled).
 * - GET/POST api/v1/tenants/{id}/location-tasks (Super-Admin): schaltet frei und benennt um. Der Body
 *   von POST ist {tasks: [{task, enabled, label?}]} (mindestens ein Eintrag, Aufgabe nur einmal);
 *   alle Einträge werden vor der ersten Änderung geprüft. Abschalten, solange Standorte die Aufgabe
 *   tragen: 409 rule_violation mit details {locations}. Unbekannte Stadt 404.
 */
final readonly class LocationTasksHandler implements RequestHandler
{
    public function __construct(private bool $superAdmin)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $entries = $context->request->method === 'POST' ? self::entries($context) : [];

        $pdo = $context->db->get();
        $tenant = $this->superAdmin
            ? (new TenantRepository($pdo)->findById($context->param('id')) ?? throw HttpException::notFound())
            : self::tenant($context);
        $tasks = new LocationTaskRepository($pdo);
        try {
            foreach ($entries as [$task, $enabled, $label]) {
                $tasks->save($tenant, $task, $enabled, $label);
            }
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['tasks' => array_map(static fn (array $row): array => [
            'task' => $row['task']->value,
            'label' => $row['label'],
            'default_label' => $row['default_label'],
            'enabled' => $row['enabled'],
            'locations' => $row['locations'],
        ], $tasks->list($tenant))]);
    }

    private static function tenant(RequestContext $context): Tenant
    {
        return $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
    }

    /**
     * @return list<array{0: LocationTask, 1: bool, 2: string|null}>
     *
     * @throws HttpException 400 invalid_request
     */
    private static function entries(RequestContext $context): array
    {
        $list = $context->body['tasks'] ?? null;
        if (!is_array($list) || !array_is_list($list) || $list === []) {
            throw HttpException::invalidRequest('Das Feld "tasks" muss eine nicht leere Liste sein.');
        }
        $result = [];
        $seen = [];
        foreach ($list as $index => $entry) {
            $n = $index + 1;
            if (!is_array($entry) || array_diff(array_keys($entry), ['task', 'enabled', 'label']) !== []) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "tasks": nur die Felder "task", "enabled" und "label" sind erlaubt.', $n));
            }
            $task = is_string($entry['task'] ?? null) ? LocationTask::tryFrom($entry['task']) : null;
            if ($task === null) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "tasks": "task" ist unbekannt.', $n));
            }
            if (isset($seen[$task->value])) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "tasks": die Aufgabe "%s" kommt doppelt vor.', $n, $task->value));
            }
            $seen[$task->value] = true;
            if (!is_bool($entry['enabled'] ?? null)) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "tasks": "enabled" muss true oder false sein.', $n));
            }
            $label = $entry['label'] ?? null;
            if ($label !== null && !is_string($label)) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "tasks": "label" muss ein Text oder null sein.', $n));
            }
            $result[] = [$task, $entry['enabled'], $label];
        }

        return $result;
    }
}
