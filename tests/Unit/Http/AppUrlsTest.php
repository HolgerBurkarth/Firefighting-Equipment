<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http;

use FirefightingEquipment\Auth\Base64Url;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Config\AppConfig;
use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AppUrls::class)]
#[CoversClass(MinimumDuration::class)]
#[UsesClass(AppConfig::class)]
#[UsesClass(Base64Url::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(ConfigLoader::class)]
#[UsesClass(OneTimeToken::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class AppUrlsTest extends TestCase
{
    public function testDefaultIsProduction(): void
    {
        self::assertSame('https://www.prodad.de/firefighting/immendingen/', new AppUrls()->area(self::tenant()));
        self::assertSame('https://www.prodad.de/firefighting/service/', new AppUrls()->area(null));
    }

    public function testPasswordResetLinkCarriesTokenAfterHash(): void
    {
        $token = OneTimeToken::generate();

        $link = AppUrls::fixed('http://localhost:8000')->passwordReset(self::tenant(), $token);

        self::assertSame('http://localhost:8000/firefighting/immendingen/passwort-zuruecksetzen#token=' . $token->value(), $link);
    }

    public function testVerifyEmailLinkHasTokenInFragment(): void
    {
        $token = OneTimeToken::generate();

        $link = AppUrls::fixed('https://www.prodad.de')->verifyEmail(self::tenant(), $token);

        self::assertSame('https://www.prodad.de/firefighting/immendingen/email-bestaetigen#token=' . $token->value(), $link);
    }

    public function testAcceptInvitationLinkHasTokenInFragment(): void
    {
        $token = OneTimeToken::generate();

        $link = AppUrls::fixed('https://www.prodad.de')->acceptInvitation(self::tenant(), $token);

        self::assertSame('https://www.prodad.de/firefighting/immendingen/einladung-annehmen#token=' . $token->value(), $link);
    }

    public function testBaseUrlFromConfigIsLoadedOnceAndOnlyWhenNeeded(): void
    {
        $loads = 0;
        $urls = new AppUrls(static function () use (&$loads): string {
            $loads++;

            return 'http://localhost:8000';
        });
        self::assertSame(0, $loads);

        $urls->area(null);
        $urls->area(self::tenant());

        self::assertSame(1, $loads);
    }

    public function testFromConfigFile(): void
    {
        $urls = AppUrls::fromConfigFile(__DIR__ . '/../../Fixtures/config/app-valid.php');

        self::assertSame('http://localhost:8000/firefighting/service/', $urls->area(null));
    }

    public function testBrokenConfigFailsOnlyOnUse(): void
    {
        $urls = AppUrls::fromConfigFile('gibt-es-nicht.php');

        $this->expectException(ConfigException::class);
        $urls->area(null);
    }

    public function testMinimumDurationSleepsForTheRemainder(): void
    {
        $now = 10.0;
        $slept = [];
        $duration = new MinimumDuration(1.5, static function () use (&$now): float {
            return $now;
        }, static function (float $seconds) use (&$slept, &$now): void {
            $slept[] = $seconds;
            $now += $seconds;
        });

        $start = $duration->start();
        $now += 0.4;

        self::assertTrue($duration->waitSince($start));
        self::assertEqualsWithDelta([1.1], $slept, 1e-9);
    }

    public function testMinimumDurationReportsOverrun(): void
    {
        $now = 0.0;
        $slept = [];
        $duration = new MinimumDuration(1.5, static function () use (&$now): float {
            return $now;
        }, static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $start = $duration->start();
        $now = 2.0;

        self::assertFalse($duration->waitSince($start));
        self::assertSame([], $slept);
    }

    public function testZeroMinimumDurationNeverWaitsOrReportsOverrun(): void
    {
        $duration = new MinimumDuration(0, static fn (): float => 0.0, static function (): void {
            self::fail('Kein Warten erwartet.');
        });

        self::assertTrue($duration->waitSince(-100.0));
    }

    public function testMinimumDurationReallyWaits(): void
    {
        $duration = new MinimumDuration(0.05);
        $start = hrtime(true);

        self::assertTrue($duration->waitSince($duration->start()));

        self::assertGreaterThanOrEqual(0.05, (hrtime(true) - $start) / 1e9);
    }

    private static function tenant(): Tenant
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        return new Tenant(1, TenantSlug::fromString('immendingen'), TenantName::fromString('Feuerwehr Immendingen'), true, $now, $now);
    }
}
