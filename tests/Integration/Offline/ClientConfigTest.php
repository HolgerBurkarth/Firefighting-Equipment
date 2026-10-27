<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Offline;

use FirefightingEquipment\Api\V1\Offline\ClientConfigHandler;
use FirefightingEquipment\Config\OfflineConfig;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\Routes;
use FirefightingEquipment\Offline\OfflineSettings;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\Tests\Support\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ClientConfigHandler::class)]
#[CoversClass(OfflineSettings::class)]
final class ClientConfigTest extends AuthTestCase
{
    public function testReturnsLimitsAndServerTime(): void
    {
        $this->account('immendingen', 'user@example.org');
        $offline = OfflineSettings::fixed(new OfflineConfig(maxAgeDays: 10, queueMaxAgeDays: 3));
        $app = $this->app(tenant: Routes::tenant(new MinimumDuration(0), new FixedClock('2026-10-27T14:05:09+01:00'), $offline));

        $response = $this->send('GET', self::url('immendingen', 'client-config'), cookie: $this->loginCookie('immendingen', 'user@example.org'), app: $app);

        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame([
            'server_time' => '2026-10-27T13:05:09Z',
            'offline' => ['max_age_days' => 10, 'queue_max_age_days' => 3],
            'max_clock_skew_seconds' => 300,
        ], self::json($response));
    }

    public function testDefaultsWithoutConfiguration(): void
    {
        $this->account('immendingen', 'user@example.org');

        $body = self::json($this->send('GET', self::url('immendingen', 'client-config'), cookie: $this->loginCookie('immendingen', 'user@example.org')));

        self::assertSame(['max_age_days' => 7, 'queue_max_age_days' => 7], $body['offline']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $body['server_time']);
    }
}
