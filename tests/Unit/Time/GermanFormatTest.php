<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Time;

use FirefightingEquipment\Time\GermanFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(GermanFormat::class)]
final class GermanFormatTest extends TestCase
{
    public function testDate(): void
    {
        self::assertSame('01.03.2027', GermanFormat::date('2027-03-01'));
        self::assertSame('31.12.1950', GermanFormat::date('1950-12-31'));
    }

    public function testRejectsOtherFormats(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        GermanFormat::date('01.03.2027');
    }

    /**
     * @return iterable<string, array{string, string}> Zeitpunkt (UTC), deutsche Zeit
     */
    public static function moments(): iterable
    {
        yield 'Sommer (MESZ)' => ['2026-06-15T10:05:59Z', '15.06.2026 12:05'];
        yield 'Winter (MEZ)' => ['2026-01-15T10:05:00Z', '15.01.2026 11:05'];
        yield 'nach Mitternacht deutscher Zeit' => ['2026-06-15T22:30:00Z', '16.06.2026 00:30'];
        yield 'Silvester' => ['2026-12-31T23:00:00Z', '01.01.2027 00:00'];
        yield 'März: vor der Umstellung' => ['2026-03-29T00:59:00Z', '29.03.2026 01:59'];
        yield 'März: nach der Umstellung' => ['2026-03-29T01:00:00Z', '29.03.2026 03:00'];
        yield 'Oktober: 2:30 MESZ' => ['2026-10-25T00:30:00Z', '25.10.2026 02:30'];
        yield 'Oktober: 2:30 MEZ' => ['2026-10-25T01:30:00Z', '25.10.2026 02:30'];
    }

    #[DataProvider('moments')]
    public function testDateTimeInGermanTime(string $utc, string $expected): void
    {
        self::assertSame($expected, GermanFormat::dateTime(new \DateTimeImmutable($utc)));
    }
}
