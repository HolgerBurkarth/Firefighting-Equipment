<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Tests\Integration\DomainTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Die Datenbank selbst lehnt ungültige Werte ab – auch an TenantSlug und TenantName vorbei.
 */
#[CoversNothing]
final class TenantSchemaTest extends DomainTestCase
{
    private const int ER_CONSTRAINT_FAILED = 4025;
    private const int ER_DUP_ENTRY = 1062;
    private const int ER_DATA_TOO_LONG = 1406;

    public function testValidRowIsAcceptedWithDefaults(): void
    {
        $this->insert('immendingen', 'Feuerwehr Immendingen');

        $row = $this->pdo()->query(
            'SELECT slug, name, active, created_at = updated_at AS same_time,'
            . ' TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) AS age FROM fe_tenants',
        )->fetch();

        self::assertSame('immendingen', $row['slug']);
        self::assertSame('Feuerwehr Immendingen', $row['name']);
        self::assertSame(1, $row['active']);
        self::assertSame(1, $row['same_time']);
        self::assertLessThan(60, abs($row['age']));
    }

    public function testFiftyCharacterSlugIsAccepted(): void
    {
        $this->insert(str_repeat('a', 50), 'Lang');

        self::assertSame(1, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'Großbuchstabe am Anfang' => ['Immendingen'];
        yield 'alles groß' => ['IMMENDINGEN'];
        yield 'Großbuchstabe in der Mitte' => ['immEndingen'];
        yield 'doppelter Bindestrich' => ['a--b'];
        yield 'Bindestrich am Anfang' => ['-a'];
        yield 'Bindestrich am Ende' => ['a-'];
        yield 'Umlaut' => ['tübingen'];
        yield 'ß' => ['gießen'];
        yield 'Unterstrich' => ['a_b'];
        yield 'Leerzeichen' => ['a b'];
        yield 'Leerzeichen am Ende' => ['immendingen '];
        yield 'Zeilenumbruch am Ende' => ["immendingen\n"];
        yield 'leer' => [''];
        yield 'reserviert' => ['service'];
        yield 'reserviert groß' => ['SERVICE'];
    }

    #[DataProvider('invalidSlugs')]
    public function testDatabaseRejectsInvalidSlug(string $slug): void
    {
        $this->assertInsertFails(self::ER_CONSTRAINT_FAILED, $slug, 'Name');
    }

    public function testDatabaseRejectsTooLongSlug(): void
    {
        $this->assertInsertFails(self::ER_DATA_TOO_LONG, str_repeat('a', 51), 'Name');
    }

    public function testDatabaseRejectsDuplicateSlug(): void
    {
        $this->insert('immendingen', 'Feuerwehr Immendingen');

        $this->assertInsertFails(self::ER_DUP_ENTRY, 'immendingen', 'Noch einmal', 1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'leer' => [''];
        yield 'nur Leerzeichen' => ['   '];
    }

    #[DataProvider('invalidNames')]
    public function testDatabaseRejectsEmptyName(string $name): void
    {
        $this->assertInsertFails(self::ER_CONSTRAINT_FAILED, 'immendingen', $name);
    }

    public function testDatabaseRejectsTooLongName(): void
    {
        $this->assertInsertFails(self::ER_DATA_TOO_LONG, 'immendingen', str_repeat('ä', 101));
    }

    public function testDatabaseRejectsActiveOtherThanZeroOrOne(): void
    {
        try {
            $this->pdo()->exec("INSERT INTO fe_tenants (slug, name, active) VALUES ('immendingen', 'Name', 2)");
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_CONSTRAINT_FAILED, $e->errorInfo[1] ?? null);
        }
    }

    public function testDatabaseRejectsChangingSlugToUppercase(): void
    {
        $this->insert('immendingen', 'Feuerwehr Immendingen');

        try {
            $this->pdo()->exec("UPDATE fe_tenants SET slug = 'Immendingen'");
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            self::assertSame(self::ER_CONSTRAINT_FAILED, $e->errorInfo[1] ?? null);
        }
        self::assertSame('immendingen', $this->pdo()->query('SELECT slug FROM fe_tenants')->fetchColumn());
    }

    private function insert(string $slug, string $name): void
    {
        $this->pdo()->prepare('INSERT INTO fe_tenants (slug, name) VALUES (?, ?)')->execute([$slug, $name]);
    }

    private function assertInsertFails(int $errorCode, string $slug, string $name, int $expectedRows = 0): void
    {
        try {
            $this->insert($slug, $name);
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            self::assertSame($errorCode, $e->errorInfo[1] ?? null, $e->getMessage());
        }
        self::assertSame($expectedRows, $this->pdo()->query('SELECT COUNT(*) FROM fe_tenants')->fetchColumn());
    }
}
