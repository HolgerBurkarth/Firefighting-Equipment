<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\DeviceNameRules;
use FirefightingEquipment\Device\DeviceRuleViolationException;
use FirefightingEquipment\Device\LocationName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeviceRuleViolationException::class)]
#[UsesClass(LocationName::class)]
#[UsesClass(DeviceNameRules::class)]
final class DeviceRuleViolationExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function contents(): iterable
    {
        yield 'ein Gerät' => [1, 0, '(1 Gerät)'];
        yield 'mehrere Geräte' => [3, 0, '(3 Geräte)'];
        yield 'ein Unterstandort' => [0, 1, '(1 aktiver Unterstandort)'];
        yield 'beides' => [2, 4, '(2 Geräte, 4 aktive Unterstandorte)'];
    }

    #[DataProvider('contents')]
    public function testNotEmptyNamesContentsAndCarriesDetails(int $devices, int $sublocations, string $text): void
    {
        $e = DeviceRuleViolationException::notEmpty(LocationName::fromString('HLF 20'), $devices, $sublocations);

        self::assertStringContainsString('Der Standort „HLF 20“ ist nicht leer ' . $text, $e->getMessage());
        self::assertSame(['devices' => $devices, 'sublocations' => $sublocations], $e->details);
    }

    public function testOtherRulesHaveNoDetails(): void
    {
        self::assertNull(DeviceRuleViolationException::tooDeep(LocationName::fromString('Fach 1'))->details);
    }
}
