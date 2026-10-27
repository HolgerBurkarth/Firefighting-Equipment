<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Offline;

use FirefightingEquipment\Device\InvalidDeviceValueException;
use FirefightingEquipment\Offline\ActionTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionTime::class)]
final class ActionTimeTest extends TestCase
{
    public function testParsesUtcAndTruncatesToSeconds(): void
    {
        self::assertSame('2026-10-27 14:05:09', ActionTime::fromString('2026-10-27T14:05:09Z')->sql());
        self::assertSame('2026-10-27 14:05:09', ActionTime::fromString('2026-10-27T14:05:09.999Z')->sql());
        self::assertSame('2024-02-29 23:59:59', ActionTime::fromString('2024-02-29T23:59:59.123456Z')->sql());
    }

    public function testFormatsInUtc(): void
    {
        self::assertSame('2026-10-27T13:05:09Z', ActionTime::format(new \DateTimeImmutable('2026-10-27T14:05:09+01:00')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'ohne Zone' => ['2026-10-27T14:05:09'];
        yield 'mit Versatz' => ['2026-10-27T14:05:09+01:00'];
        yield 'kleines z' => ['2026-10-27T14:05:09z'];
        yield 'Leerzeichen statt T' => ['2026-10-27 14:05:09Z'];
        yield 'ohne Sekunden' => ['2026-10-27T14:05Z'];
        yield 'kein Kalendertag' => ['2026-02-30T14:05:09Z'];
        yield 'Stunde 24' => ['2026-10-27T24:00:00Z'];
        yield 'Schaltsekunde' => ['2026-12-31T23:59:60Z'];
        yield 'zu viele Nachkommastellen' => ['2026-10-27T14:05:09.1234567Z'];
        yield 'Zeilenumbruch' => ["2026-10-27T14:05:09Z\n"];
        yield 'vor 2000' => ['1999-12-31T23:59:59Z'];
        yield 'leer' => [''];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $value): void
    {
        $this->expectException(InvalidDeviceValueException::class);
        ActionTime::fromString($value);
    }
}
