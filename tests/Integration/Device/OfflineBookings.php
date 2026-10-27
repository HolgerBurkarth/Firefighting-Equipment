<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Device;

use FirefightingEquipment\Config\OfflineConfig;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Tests\Support\FixedClock;

/**
 * Offline-Buchungen in Tests auf Basis von DeviceApiTestCase: feste Uhr auf der Zeit der Datenbank
 * (Sekunden), damit state_changed_at (vom Server geschrieben) und Aktionszeiten vergleichbar sind;
 * Zeitpunkte relativ dazu („-2 hours“). Jede Buchung erhält eine neue client_ref, falls keine
 * angegeben ist. In setUp() setUpOffline() aufrufen.
 */
trait OfflineBookings
{
    private \DateTimeImmutable $now;
    private FixedClock $clock;
    private Application $offlineApp;
    private int $refCounter = 0;

    private function setUpOffline(OfflineConfig $config = new OfflineConfig()): void
    {
        $now = (string) $this->pdo()->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
        $this->clock = new FixedClock($this->now->format('Y-m-d\TH:i:s\Z'));
        $this->useOfflineConfig($config);
    }

    private function useOfflineConfig(OfflineConfig $config): void
    {
        $this->offlineApp = $this->app(tenant: Routes::tenant(new MinimumDuration(0), $this->clock, OfflineSettings::fixed($config)));
    }

    /**
     * Offline-Buchung, die 200 liefern muss (Standard: als Benutzer).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function offline(int $id, array $body, ?string $cookie = null): array
    {
        $response = $this->offlineResponse($id, $body, $cookie);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function offlineResponse(int $id, array $body, ?string $cookie = null): JsonResponse
    {
        $body += ['client_ref' => $this->ref()];

        return $this->send('POST', self::url('immendingen', "devices/{$id}/state"), $body, $cookie ?? $this->user, app: $this->offlineApp);
    }

    private function ref(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', ++$this->refCounter);
    }

    private function lastRef(): string
    {
        return sprintf('00000000-0000-4000-8000-%012d', $this->refCounter);
    }

    /** Zeitpunkt relativ zu jetzt als occurred_at (ISO 8601, UTC). */
    private function at(string $modifier): string
    {
        return $this->now->modify($modifier)->format('Y-m-d\TH:i:s\Z');
    }

    /** Zeitpunkt relativ zu jetzt als DATETIME. */
    private function sql(string $modifier): string
    {
        return $this->now->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function setStateChangedAt(int $id, string $modifier): void
    {
        $this->pdo()->prepare('UPDATE fe_devices SET state_changed_at = ? WHERE id = ?')->execute([$this->sql($modifier), $id]);
    }
}
