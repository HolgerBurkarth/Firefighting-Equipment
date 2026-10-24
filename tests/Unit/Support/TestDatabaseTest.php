<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Support;

use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prüft den Schutz, der Tests nur gegen Datenbanken mit Endung _test zulässt.
 */
final class TestDatabaseTest extends TestCase
{
    public function testNameEndingWithTestIsAccepted(): void
    {
        self::assertTrue(TestDatabase::isTestDatabaseName('firefighting_test'));

        TestDatabase::assertIsTestDatabaseName('firefighting_test');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonTestNames(): iterable
    {
        yield 'Produktiv-Name' => ['firefighting'];
        yield '_test am Anfang' => ['test_firefighting'];
        yield '_test in der Mitte' => ['firefighting_test_x'];
        yield 'Großbuchstaben' => ['firefighting_TEST'];
        yield 'ohne Unterstrich' => ['firefightingtest'];
        yield 'nur _test' => ['_test'];
        yield 'Zeilenumbruch am Ende' => ["firefighting_test\n"];
    }

    #[DataProvider('nonTestNames')]
    public function testOtherNamesAreRejected(string $name): void
    {
        self::assertFalse(TestDatabase::isTestDatabaseName($name));

        $this->expectException(\RuntimeException::class);
        TestDatabase::assertIsTestDatabaseName($name);
    }
}
