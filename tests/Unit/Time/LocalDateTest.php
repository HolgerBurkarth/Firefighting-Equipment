<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Time;

use FirefightingEquipment\Tests\Support\FixedClock;
use FirefightingEquipment\Time\LocalDate;
use FirefightingEquipment\Time\SystemClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalDate::class)]
#[CoversClass(SystemClock::class)]
final class LocalDateTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> Zeitpunkt (UTC), Tag in deutscher Zeit
     */
    public static function midnights(): iterable
    {
        yield 'Sommer: 23:59:59 MESZ' => ['2026-06-15T21:59:59Z', '2026-06-15'];
        yield 'Sommer: 00:00 MESZ' => ['2026-06-15T22:00:00Z', '2026-06-16'];
        yield 'Winter: 23:59:59 MEZ' => ['2026-01-15T22:59:59Z', '2026-01-15'];
        yield 'Winter: 00:00 MEZ' => ['2026-01-15T23:00:00Z', '2026-01-16'];
        yield 'Jahreswechsel' => ['2026-12-31T23:00:00Z', '2027-01-01'];
        yield 'März: Mitternacht vor der Umstellung (MEZ)' => ['2026-03-28T23:00:00Z', '2026-03-29'];
        yield 'März: kurz vor 2 Uhr MEZ' => ['2026-03-29T00:59:59Z', '2026-03-29'];
        yield 'März: 3 Uhr MESZ' => ['2026-03-29T01:00:00Z', '2026-03-29'];
        yield 'März: Mitternacht nach der Umstellung (MESZ)' => ['2026-03-29T22:00:00Z', '2026-03-30'];
        yield 'Oktober: Mitternacht vor der Umstellung (MESZ)' => ['2026-10-24T22:00:00Z', '2026-10-25'];
        yield 'Oktober: 23:59:59 MESZ am Vortag' => ['2026-10-24T21:59:59Z', '2026-10-24'];
        yield 'Oktober: 23:30 MEZ nach der Umstellung' => ['2026-10-25T22:30:00Z', '2026-10-25'];
        yield 'Oktober: Mitternacht nach der Umstellung (MEZ)' => ['2026-10-25T23:00:00Z', '2026-10-26'];
        yield 'andere Zone der Uhr' => ['2026-06-16T01:30:00+03:00', '2026-06-16'];
    }

    #[DataProvider('midnights')]
    public function testTodayFollowsGermanTime(string $now, string $expected): void
    {
        self::assertSame($expected, LocalDate::today(new FixedClock($now)));
    }

    public function testSystemClockIsNow(): void
    {
        $before = time();
        $now = new SystemClock()->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
    }

    public function testAddDaysIgnoresDaylightSavingTime(): void
    {
        self::assertSame('2026-03-30', LocalDate::addDays('2026-03-28', 2));
        self::assertSame('2026-10-26', LocalDate::addDays('2026-10-24', 2));
        self::assertSame('2028-02-29', LocalDate::addDays('2028-01-30', 30));
        self::assertSame('2027-01-30', LocalDate::addDays('2026-12-31', 30));
        self::assertSame('2026-06-14', LocalDate::addDays('2026-06-15', -1));
        self::assertSame('2027-06-15', LocalDate::addDays('2026-06-15', 365));
    }

    public function testDaysBetween(): void
    {
        self::assertSame(0, LocalDate::daysBetween('2026-06-15', '2026-06-15'));
        self::assertSame(1, LocalDate::daysBetween('2026-06-15', '2026-06-16'));
        self::assertSame(-1, LocalDate::daysBetween('2026-06-15', '2026-06-14'));
        self::assertSame(2, LocalDate::daysBetween('2026-03-28', '2026-03-30'));
        self::assertSame(-2, LocalDate::daysBetween('2026-10-26', '2026-10-24'));
        self::assertSame(366, LocalDate::daysBetween('2028-01-01', '2029-01-01'));
    }

    /**
     * @return iterable<string, array{string, string}> Tag, Beginn in UTC
     */
    public static function startsOfDay(): iterable
    {
        yield 'Sommer' => ['2026-06-15', '2026-06-14 22:00:00'];
        yield 'Winter' => ['2026-01-15', '2026-01-14 23:00:00'];
        yield 'Tag der Umstellung im März (beginnt in MEZ)' => ['2026-03-29', '2026-03-28 23:00:00'];
        yield 'Tag nach der Umstellung im März' => ['2026-03-30', '2026-03-29 22:00:00'];
        yield 'Tag der Umstellung im Oktober (beginnt in MESZ)' => ['2026-10-25', '2026-10-24 22:00:00'];
        yield 'Tag nach der Umstellung im Oktober' => ['2026-10-26', '2026-10-25 23:00:00'];
        yield 'Neujahr' => ['2027-01-01', '2026-12-31 23:00:00'];
    }

    #[DataProvider('startsOfDay')]
    public function testStartOfDayUtc(string $date, string $expected): void
    {
        self::assertSame($expected, LocalDate::startOfDayUtc($date));
    }

    public function testStartOfDayRejectsInvalidDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LocalDate::startOfDayUtc('2026-02-30');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'leer' => [''];
        yield 'kein Kalendertag' => ['2026-02-30'];
        yield 'mit Uhrzeit' => ['2026-06-15 10:00'];
        yield 'anderes Format' => ['15.06.2026'];
    }

    #[DataProvider('invalidDates')]
    public function testRejectsInvalidDates(string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LocalDate::addDays($date, 1);
    }
}
