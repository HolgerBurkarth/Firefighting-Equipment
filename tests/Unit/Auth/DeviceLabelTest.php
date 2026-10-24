<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Auth;

use FirefightingEquipment\Auth\DeviceLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceLabel::class)]
final class DeviceLabelTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'Chrome Android' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Chrome auf Android',
        ];
        yield 'Safari iPhone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Safari auf iOS',
        ];
        yield 'Chrome iPad' => [
            'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0 Mobile/15E148 Safari/604.1',
            'Chrome auf iOS',
        ];
        yield 'Firefox Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
            'Firefox auf Windows',
        ];
        yield 'Edge Windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            'Edge auf Windows',
        ];
        yield 'Opera macOS' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
            'Opera auf macOS',
        ];
        yield 'Safari macOS' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            'Safari auf macOS',
        ];
        yield 'Samsung Internet' => [
            'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
            'Samsung Internet auf Android',
        ];
        yield 'Firefox Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', 'Firefox auf Linux'];
        yield 'ChromeOS' => [
            'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Chrome auf ChromeOS',
        ];
        yield 'curl' => ['curl/8.5.0', 'curl'];
        yield 'nur System' => ['SomeApp/1.0 (Android 14)', 'Browser auf Android'];
        yield 'unbekannt' => ['Geheimer-Bot/1.0 (+https://example.org/meine-id-123)', DeviceLabel::UNKNOWN];
        yield 'leer' => ['', DeviceLabel::UNKNOWN];
        yield 'fehlt' => [null, DeviceLabel::UNKNOWN];
    }

    #[DataProvider('userAgents')]
    public function testLabel(?string $userAgent, string $expected): void
    {
        self::assertSame($expected, DeviceLabel::fromUserAgent($userAgent));
    }

    public function testLabelNeverContainsUserAgentParts(): void
    {
        $label = DeviceLabel::fromUserAgent('Mozilla/5.0 (Windows NT 10.0; Benutzer-Max-Mustermann) Firefox/121.0');

        self::assertSame('Firefox auf Windows', $label);
    }
}
