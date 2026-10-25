<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Invitation;

use FirefightingEquipment\Invitation\InvalidInvitationValueException;
use FirefightingEquipment\Invitation\InvitationCodeLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitationCodeLabel::class)]
#[UsesClass(InvalidInvitationValueException::class)]
final class InvitationCodeLabelTest extends TestCase
{
    public function testIsTrimmed(): void
    {
        self::assertSame('Dienstabend März', InvitationCodeLabel::fromString("  Dienstabend März \n")->value);
    }

    public function testMaximumLength(): void
    {
        self::assertSame(100, mb_strlen(InvitationCodeLabel::fromString(str_repeat('ä', 100))->value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidLabels(): iterable
    {
        yield 'leer' => ['   ', 'Die Bezeichnung darf nicht leer sein.'];
        yield 'zu lang' => [str_repeat('a', 101), 'Die Bezeichnung darf höchstens 100 Zeichen lang sein (angegeben: 101).'];
        yield 'Zeilenumbruch' => ["Zeile 1\nZeile 2", 'Die Bezeichnung darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'];
        yield 'kein UTF-8' => ["a\xFF", 'Die Bezeichnung ist kein gültiger UTF-8-Text.'];
    }

    #[DataProvider('invalidLabels')]
    public function testInvalidLabel(string $value, string $message): void
    {
        $this->expectException(InvalidInvitationValueException::class);
        $this->expectExceptionMessage($message);

        InvitationCodeLabel::fromString($value);
    }
}
