<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant;

use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantName::class)]
#[UsesClass(InvalidTenantValueException::class)]
final class TenantNameTest extends TestCase
{
    public function testNameIsAccepted(): void
    {
        self::assertSame('Feuerwehr Immendingen', TenantName::fromString('Feuerwehr Immendingen')->value);
    }

    public function testWhitespaceAtEdgesIsRemoved(): void
    {
        self::assertSame('Feuerwehr Tübingen', TenantName::fromString("  Feuerwehr Tübingen\t")->value);
    }

    public function testExactly100CharactersWithUmlautsAreAccepted(): void
    {
        $value = str_repeat('ä', 100);

        self::assertSame($value, TenantName::fromString($value)->value);
    }

    public function testMoreThan100CharactersAreRejected(): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage('höchstens 100 Zeichen lang sein (angegeben: 101)');

        TenantName::fromString(str_repeat('ä', 101));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'leer' => ['', 'Der Name darf nicht leer sein.'];
        yield 'nur Leerzeichen' => ['   ', 'Der Name darf nicht leer sein.'];
        yield 'Zeilenumbruch' => ["Feuerwehr\nImmendingen", 'Steuerzeichen'];
        yield 'Tabulator' => ["Feuerwehr\tImmendingen", 'Steuerzeichen'];
        yield 'ungültiges UTF-8' => ["Feuerwehr \xFF", 'kein gültiger UTF-8-Text'];
    }

    #[DataProvider('invalidNames')]
    public function testInvalidNameIsRejected(string $value, string $message): void
    {
        $this->expectException(InvalidTenantValueException::class);
        $this->expectExceptionMessage($message);

        TenantName::fromString($value);
    }
}
