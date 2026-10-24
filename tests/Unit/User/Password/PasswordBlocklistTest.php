<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User\Password;

use FirefightingEquipment\User\Password\PasswordBlocklist;
use FirefightingEquipment\User\Password\PasswordPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordBlocklist::class)]
#[UsesClass(PasswordPolicy::class)]
final class PasswordBlocklistTest extends TestCase
{
    public function testFileFormat(): void
    {
        $content = (string) file_get_contents(PasswordBlocklist::DEFAULT_PATH);

        self::assertTrue(mb_check_encoding($content, 'UTF-8'));
        self::assertStringNotContainsString("\r", $content, 'Nur LF als Zeilenende.');
        self::assertStringNotContainsString("\u{FEFF}", $content, 'Kein BOM.');
        self::assertStringEndsWith("\n", $content);
    }

    public function testEntriesAreFoldedTrimmedAndUnique(): void
    {
        $entries = [];
        foreach (explode("\n", (string) file_get_contents(PasswordBlocklist::DEFAULT_PATH)) as $number => $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            self::assertSame(trim($line), $line, sprintf('Zeile %d: Leerraum am Rand.', $number + 1));
            self::assertSame(PasswordPolicy::fold($line), $line, sprintf('Zeile %d: nicht in Vergleichsform.', $number + 1));
            self::assertArrayNotHasKey($line, $entries, sprintf('Zeile %d: doppelt (%s).', $number + 1, $line));
            $entries[$line] = true;
        }

        self::assertGreaterThan(300, count($entries));
    }

    public function testDefaultListContainsFirefightingVariants(): void
    {
        $list = PasswordBlocklist::fromFile(PasswordBlocklist::DEFAULT_PATH);

        foreach (['feuerwehr112', 'feuerwehr', 'florian112', 'atemschutz', 'passwort', 'password', 'qwertz', '1234567890'] as $entry) {
            self::assertTrue($list->contains($entry), $entry);
        }
        self::assertFalse($list->contains('unsere feuerwehr fährt rot'));
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $list = PasswordBlocklist::fromFile(PasswordBlocklist::DEFAULT_PATH);

        self::assertFalse($list->contains(''));
        self::assertFalse($list->contains('# ziffern- und tastaturfolgen'));
    }

    public function testEntriesAreFolded(): void
    {
        $list = PasswordBlocklist::fromEntries(['Straße', 'MÜNCHEN']);

        self::assertTrue($list->contains('strasse'));
        self::assertTrue($list->contains('münchen'));
        self::assertFalse($list->contains('Straße'));
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Sperrliste nicht lesbar: gibt-es-nicht.txt');

        PasswordBlocklist::fromFile('gibt-es-nicht.txt');
    }
}
