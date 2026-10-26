<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Inspection;

use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Router;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Tests\Integration\Device\DeviceApiTestCase;
use FirefightingEquipment\Tests\Support\FixedClock;
use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Basis für Tests der Prüffristen: wie DeviceApiTestCase, die Anwendung verwendet aber die Uhr
 * $clock (Standard 2026-06-15 12:00 MESZ, „heute“ = TODAY).
 */
abstract class InspectionApiTestCase extends DeviceApiTestCase
{
    protected const string TODAY = '2026-06-15';

    protected FixedClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-06-15T10:00:00Z');

        parent::setUp();
    }

    protected function app(?Router $service = null, ?Router $tenant = null, ?PasswordHasher $hasher = null): Application
    {
        return parent::app($service, $tenant ?? Routes::tenant(new MinimumDuration(0), $this->clock), $hasher);
    }

    /**
     * Gerät der Stadt immendingen (Atemschutzmaske in G1) mit Prüfdatum.
     *
     * @param array<string, scalar|null> $overrides
     */
    protected function deviceDue(string $identifier, ?string $due, array $overrides = []): int
    {
        return $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => $identifier, 'next_inspection_due' => $due, ...$overrides]);
    }

    /**
     * GET-Anfrage, die 200 liefern muss (Standard: als Benutzer); Antwort als JSON.
     *
     * @return array<string, mixed>
     */
    protected function get(string $path, ?string $cookie = null): array
    {
        $response = $this->api('GET', $path, cookie: $cookie ?? $this->user);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }
}
