<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant\Logo;

use FirefightingEquipment\Tenant\AccentColor;
use FirefightingEquipment\Tenant\Logo\DefaultAppIcon;
use FirefightingEquipment\Tenant\Logo\LogoImages;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tests\Support\TestPng;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Die committeten Dateien in resources/app-icon/ (scripts/generate-default-app-icon.php).
 */
#[CoversClass(DefaultAppIcon::class)]
#[UsesClass(LogoImages::class)]
#[UsesClass(LogoSize::class)]
final class DefaultAppIconTest extends TestCase
{
    public function testAllSizesExistAsCleanPng(): void
    {
        foreach (LogoSize::cases() as $size) {
            $png = DefaultAppIcon::png($size);
            $info = getimagesizefromstring($png);

            self::assertIsArray($info);
            self::assertSame([$size->value, $size->value, 'image/png'], [$info[0], $info[1], $info['mime']]);
            self::assertSame([], array_values(array_diff(TestPng::chunkTypes($png), ['IHDR', 'pHYs', 'IDAT', 'IEND'])));
            self::assertSame([0, (int) hexdec(substr(AccentColor::DEFAULT, 1))], TestPng::pixel($png, 0, 0), 'Hintergrund in der Standardfarbe');
        }
    }

    public function testVersionIsStableHash(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}\z/', DefaultAppIcon::version());
        self::assertSame(DefaultAppIcon::version(), DefaultAppIcon::version());
    }
}
