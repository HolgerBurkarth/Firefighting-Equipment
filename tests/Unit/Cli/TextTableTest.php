<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\TextTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextTable::class)]
final class TextTableTest extends TestCase
{
    public function testColumnsAlignByCharactersAndLastColumnIsNotPadded(): void
    {
        self::assertSame(
            [
                'ID  Kürzel       Name',
                '1   immendingen  Feuerwehr Immendingen',
                '12  ä            x',
            ],
            TextTable::render([
                ['ID', 'Kürzel', 'Name'],
                ['1', 'immendingen', 'Feuerwehr Immendingen'],
                ['12', 'ä', 'x'],
            ]),
        );
    }

    public function testEmpty(): void
    {
        self::assertSame([], TextTable::render([]));
    }
}
