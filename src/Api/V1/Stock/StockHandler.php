<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Stock;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\Stock\StockRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Sollbestand (Phase 2, P2.7):
 * - GET api/v1/stock (Bereich `locations` Ansehen): {locations [{location {id, name, path, level}, rules [{id,
 *   min_count, types [{id, name}], have, missing}], out_of_service}], summary {locations, out_of_service}} nur
 *   für Standorte mit Regeln; `have` zählt die einsatzbereiten Ausrüstungen dieser Typen am Standort (bei
 *   der Ebene 1 samt Fächern), `missing` = fehlende Anzahl. Mit Datenfilter nur Sichtbares.
 * - POST api/v1/locations/{id}/stock-rules {rules: [{min_count (1–99), device_type_ids: [1–20 Typen]}]}
 *   (Bereich `locations` Ändern; F3: nie mit Datenfilter): ersetzt alle Regeln des Standorts (höchstens 20;
 *   leere Liste = keine). Antwort: der Eintrag des Standorts wie bei GET (`location` und `stock` {rules,
 *   out_of_service} oder null ohne Regeln). Gesperrter Standort 409, Typ unbekannt 400, Standort unbekannt 404.
 */
final readonly class StockHandler implements RequestHandler
{
    public function __construct(private bool $save)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $stock = new StockRepository($context->db->get(), $context->visibility());

        if (!$this->save) {
            $entries = $stock->overview($tenant);

            return new JsonResponse([
                'locations' => $entries,
                'summary' => [
                    'locations' => count($entries),
                    'out_of_service' => count(array_filter($entries, static fn (array $entry): bool => $entry['out_of_service'])),
                ],
            ]);
        }

        $id = $context->param('id');
        DeviceFields::rejectUnknown($context, ['rules']);
        try {
            $stock->replace($tenant, $id, self::rules($context));
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }
        $entry = $stock->overview($tenant, $id)[0] ?? null;

        return new JsonResponse(['stock' => $entry === null ? null : ['rules' => $entry['rules'], 'out_of_service' => $entry['out_of_service']]]);
    }

    /**
     * @return list<array{min_count: int, device_type_ids: list<int>}>
     *
     * @throws HttpException 400 invalid_request
     */
    private static function rules(RequestContext $context): array
    {
        $rules = $context->body['rules'] ?? null;
        if (!is_array($rules) || !array_is_list($rules)) {
            throw HttpException::invalidRequest('Das Feld "rules" muss eine Liste sein (leer = keine Regeln).');
        }
        $result = [];
        foreach ($rules as $index => $rule) {
            if (!is_array($rule) || array_is_list($rule) || array_diff(array_keys($rule), ['min_count', 'device_type_ids']) !== []) {
                throw HttpException::invalidRequest(sprintf('Regel %d in "rules" muss ein Objekt mit "min_count" und "device_type_ids" sein.', $index + 1));
            }
            $min = $rule['min_count'] ?? null;
            $types = $rule['device_type_ids'] ?? null;
            if (!is_int($min) || !is_array($types) || !array_is_list($types)) {
                throw HttpException::invalidRequest(sprintf('Regel %d in "rules": "min_count" muss eine Ganzzahl und "device_type_ids" eine Liste sein.', $index + 1));
            }
            foreach ($types as $type) {
                if (!is_int($type) || $type < 1 || $type > DeviceFields::MAX_ID) {
                    throw HttpException::invalidRequest(sprintf('Regel %d in "rules": "device_type_ids" enthält eine ungültige ID.', $index + 1));
                }
            }
            /** @var list<int> $types */
            $result[] = ['min_count' => $min, 'device_type_ids' => $types];
        }

        return $result;
    }
}
