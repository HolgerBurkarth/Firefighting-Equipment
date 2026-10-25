<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Invitation;

use FirefightingEquipment\Invitation\InvitationCodeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InvitationCodeValue::class)]
final class InvitationCodeValueTest extends TestCase
{
    public function testAlphabetHasNoAmbiguousCharacters(): void
    {
        self::assertSame(31, strlen(InvitationCodeValue::ALPHABET));
        foreach (['0', 'O', '1', 'I', 'L'] as $char) {
            self::assertStringNotContainsString($char, InvitationCodeValue::ALPHABET);
        }
    }

    public function testGeneratedCodeUsesAlphabetOnly(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; ++$i) {
            $code = InvitationCodeValue::generate();
            self::assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{12}$/D', $code->value());
            $seen[$code->value()] = true;
        }

        self::assertCount(200, $seen, 'Zufällig, keine Wiederholung');
    }

    public function testDisplayAndHash(): void
    {
        $code = InvitationCodeValue::fromInput('ABCDEFGHJKMN') ?? self::fail('Code erwartet.');

        self::assertSame('ABCD-EFGH-JKMN', $code->display());
        self::assertSame(hash('sha256', 'ABCDEFGHJKMN', true), $code->hash());
        self::assertSame(32, strlen($code->hash()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentInputs(): iterable
    {
        yield 'Anzeigeform' => ['ABCD-EFGH-JKMN'];
        yield 'klein' => ['abcd-efgh-jkmn'];
        yield 'gemischt ohne Trenner' => ['aBcDeFgHjKmN'];
        yield 'Leerzeichen' => [' ABCD EFGH JKMN '];
        yield 'Tab und doppelte Bindestriche' => ["ABCD--EFGH\tJKMN"];
    }

    #[DataProvider('equivalentInputs')]
    public function testInputIsNormalized(string $input): void
    {
        self::assertSame('ABCDEFGHJKMN', InvitationCodeValue::fromInput($input)?->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'leer' => [''];
        yield 'zu kurz' => ['ABCD-EFGH-JKM'];
        yield 'zu lang' => ['ABCD-EFGH-JKMNP'];
        yield 'Null' => ['ABCD-EFGH-JKM0'];
        yield 'O' => ['ABCD-EFGH-JKMO'];
        yield 'Eins' => ['ABCD-EFGH-JKM1'];
        yield 'I' => ['ABCD-EFGH-JKMI'];
        yield 'L' => ['ABCD-EFGH-JKML'];
        yield 'Umlaut' => ['ABCD-EFGH-JKMÄ'];
        yield 'Unterstrich' => ['ABCD_EFGH_JKMN'];
        yield 'kein UTF-8' => ["ABCD-EFGH-JKM\xFF"];
        yield 'sehr lang' => [str_repeat(' ', 100) . 'ABCDEFGHJKMN'];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejected(string $input): void
    {
        self::assertNull(InvitationCodeValue::fromInput($input));
    }

    public function testDebugInfoHidesValue(): void
    {
        $code = InvitationCodeValue::fromInput('ABCDEFGHJKMN') ?? self::fail('Code erwartet.');

        self::assertStringNotContainsString('ABCDEFGHJKMN', print_r($code, true));
    }
}
