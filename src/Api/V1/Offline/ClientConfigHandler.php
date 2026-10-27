<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Offline;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Offline\ActionTime;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Time\Clock;

/**
 * GET api/v1/client-config (angemeldet, nur unter Städten): Einstellungen für das Frontend.
 * Antwort {server_time, offline {max_age_days, queue_max_age_days}, max_clock_skew_seconds};
 * server_time in UTC (ISO 8601 mit Z), damit das Handy die Abweichung seiner Uhr erkennt.
 */
final readonly class ClientConfigHandler implements RequestHandler
{
    public function __construct(private Clock $clock, private OfflineSettings $offline)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $config = $this->offline->config();

        return new JsonResponse([
            'server_time' => ActionTime::format($this->clock->now()),
            'offline' => [
                'max_age_days' => $config->maxAgeDays,
                'queue_max_age_days' => $config->queueMaxAgeDays,
            ],
            'max_clock_skew_seconds' => OfflineSettings::MAX_CLOCK_SKEW_SECONDS,
        ]);
    }
}
