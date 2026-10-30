<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Device\Import;

use FirefightingEquipment\Api\V1\Devices\DeviceCsv;
use FirefightingEquipment\Device\Import\DeviceImportTemplate;
use FirefightingEquipment\Device\Import\ImportSuggestions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImportSuggestions::class)]
#[CoversClass(DeviceImportTemplate::class)]
final class ImportSuggestionsTest extends TestCase
{
    public function testKeyIgnoresCaseWhitespaceAndUmlautSpelling(): void
    {
        self::assertSame('hlf20/g1', ImportSuggestions::key(" HLF 20 /\tG1 "));
        self::assertSame('waermebildkamera', ImportSuggestions::key('Wärmebildkamera'));
        self::assertSame('strasse', ImportSuggestions::key('STRAẞE'));
        self::assertSame('oefen', ImportSuggestions::key('Öfen'));
    }

    public function testDistanceCountsUnicodeCharactersAndStopsAboveMax(): void
    {
        self::assertSame(0, ImportSuggestions::distance(mb_str_split('gerät'), mb_str_split('gerät'), 2));
        self::assertSame(1, ImportSuggestions::distance(mb_str_split('gerät'), mb_str_split('gerat'), 2));
        self::assertSame(2, ImportSuggestions::distance(mb_str_split('lager'), mb_str_split('lgaer'), 2));
        self::assertSame(3, ImportSuggestions::distance(mb_str_split('abc'), mb_str_split('xyz'), 2), 'über max: max + 1');
        self::assertSame(2, ImportSuggestions::distance(mb_str_split('a'), mb_str_split('abcdef'), 1), 'Längenunterschied');
        self::assertSame(1, ImportSuggestions::distance([], mb_str_split('a'), 3));
    }

    public function testSuggestsClosestNamesSortedNaturally(): void
    {
        $suggestions = ImportSuggestions::forNames(['LF 16', 'LF 10', 'Lager', 'HLF 20', 'Wärmebildkamera']);

        self::assertSame(['HLF 20'], $suggestions->suggest('hlf20'));
        self::assertSame(['LF 10', 'LF 16'], $suggestions->suggest('LF 1'));
        self::assertSame(['Wärmebildkamera'], $suggestions->suggest('Waermebildkamera'));
        self::assertSame(['Wärmebildkamera'], $suggestions->suggest('Wärmebildkammera'));
        self::assertSame([], $suggestions->suggest('Wache Nord'));
        self::assertSame([], $suggestions->suggest('   '));
    }

    public function testAllowedDistanceGrowsWithLength(): void
    {
        $suggestions = ImportSuggestions::forNames(['G1', 'Lager', 'Atemschutzmaske']);

        self::assertSame(['G1'], $suggestions->suggest('G2'), 'kurz: 1');
        self::assertSame([], $suggestions->suggest('Lgaer'), 'Länge 5: nur 1');
        self::assertSame(['Atemschutzmaske'], $suggestions->suggest('Atemshutzmaskke'), 'Länge 15: bis 3');
        self::assertSame([], $suggestions->suggest('Atemschutz'), 'Präfix zu kurz');
    }

    public function testLocationsAlsoBySublocationName(): void
    {
        $suggestions = ImportSuggestions::forLocations([['HLF 20', null], ['HLF 20 / G1', 'G1'], ['LF 10 / G1', 'G1'], ['Lager', null]]);

        self::assertSame(['HLF 20 / G1', 'LF 10 / G1'], $suggestions->suggest('g1'));
        self::assertSame(['HLF 20 / G1'], $suggestions->suggest('HLF20/G1'));
        self::assertSame(['HLF 20 / G1'], $suggestions->suggest('HLF 20/G 1'));
    }

    public function testAtMostThreeSuggestionsAndLimitedNumberOfSearches(): void
    {
        $suggestions = ImportSuggestions::forNames(['LF 11', 'LF 12', 'LF 13', 'LF 14']);

        self::assertSame(['LF 11', 'LF 12', 'LF 13'], $suggestions->suggest('LF 1'));
        for ($i = 1; $i < ImportSuggestions::MAX_VALUES; $i++) {
            $suggestions->suggest('x');
        }
        self::assertSame([], $suggestions->suggest('LF 1'), 'nach MAX_VALUES Suchen keine weiteren');
    }

    public function testNumericNamesStayStrings(): void
    {
        self::assertSame(['112'], ImportSuggestions::forNames(['112'])->suggest('113'));
    }

    public function testSentence(): void
    {
        self::assertSame('', ImportSuggestions::sentence([]));
        self::assertSame('Meinten Sie „HLF 20“?', ImportSuggestions::sentence(['HLF 20']));
        self::assertSame('Meinten Sie „LF 10“ oder „LF 16“?', ImportSuggestions::sentence(['LF 10', 'LF 16']));
        self::assertSame('Meinten Sie „A“, „B“ oder „C“?', ImportSuggestions::sentence(['A', 'B', 'C']));
    }

    public function testTemplateHeaderIsExportHeaderAndExamplesAreRecognized(): void
    {
        self::assertSame(DeviceCsv::DEVICE_HEADER, DeviceImportTemplate::header());
        self::assertTrue(DeviceImportTemplate::isExample('BEISPIEL-1'));
        self::assertTrue(DeviceImportTemplate::isExample('beispiel-2'));
        self::assertFalse(DeviceImportTemplate::isExample('BEISPIEL-3'));
        self::assertFalse(DeviceImportTemplate::isExample('MA-01'));
    }
}
