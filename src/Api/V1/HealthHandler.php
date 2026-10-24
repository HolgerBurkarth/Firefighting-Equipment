<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1;

use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/health: prüft die Datenbankverbindung. Wird vom Deploy-Skript aufgerufen.
 */
final readonly class HealthHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        try {
            $context->db->get()->query('SELECT 1')->fetchColumn();
        } catch (ConnectionException | \PDOException $e) {
            $context->errors->report('health', $e);

            return new JsonResponse(['status' => 'error', 'database' => 'error'], 503);
        }

        return new JsonResponse(['status' => 'ok', 'database' => 'ok']);
    }
}
