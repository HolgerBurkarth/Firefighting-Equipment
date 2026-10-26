<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device;

use FirefightingEquipment\Device\LocationKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocationKind::class)]
final class LocationKindTest extends TestCase
{
    public function testValuesMatchSchemaAndHaveGermanLabels(): void
    {
        $labels = [];
        foreach (LocationKind::cases() as $kind) {
            $labels[$kind->value] = $kind->label();
        }

        self::assertSame(['vehicle' => 'Fahrzeug', 'room' => 'Raum/Lager', 'external' => 'Extern'], $labels);
    }
}
