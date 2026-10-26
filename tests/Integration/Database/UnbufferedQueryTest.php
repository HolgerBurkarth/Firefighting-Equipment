<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Database;

use FirefightingEquipment\Database\UnbufferedQuery;
use FirefightingEquipment\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Ungepuffertes Lesen: Während des Lesens ist die Verbindung ungepuffert (eine zweite Abfrage wird
 * abgelehnt, das Ergebnis liegt also nicht vollständig im Speicher), danach wieder wie vorher.
 */
#[CoversClass(UnbufferedQuery::class)]
final class UnbufferedQueryTest extends IntegrationTestCase
{
    private const string SQL = 'SELECT seq AS n FROM seq_1_to_5 WHERE seq >= ? ORDER BY seq';

    public function testReadsRowsUnbufferedAndRestoresSetting(): void
    {
        $rows = UnbufferedQuery::rows($this->pdo(), self::SQL, [2], static fn (array $row): int => (int) $row['n']);

        self::assertSame(2, $rows->current());
        self::assertFalse((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));
        try {
            $this->pdo()->query('SELECT 1');
            self::fail('Zweite Abfrage während des ungepufferten Lesens erwartet einen Fehler.');
        } catch (\PDOException) {
            // erwartet: Das Ergebnis wird erst beim Lesen vom Server geholt.
        }

        $rest = [];
        for ($rows->next(); $rows->valid(); $rows->next()) {
            $rest[] = $rows->current();
        }

        self::assertSame([3, 4, 5], $rest);
        self::assertTrue((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));
        self::assertSame(1, (int) $this->pdo()->query('SELECT 1')->fetchColumn());
    }

    public function testDiscardedGeneratorClosesQuery(): void
    {
        $rows = UnbufferedQuery::rows($this->pdo(), self::SQL, [1], static fn (array $row): int => (int) $row['n']);
        self::assertSame(1, $rows->current());

        unset($rows);

        self::assertTrue((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));
        self::assertSame(1, (int) $this->pdo()->query('SELECT 1')->fetchColumn());
    }

    public function testKeepsUnbufferedSettingOfCaller(): void
    {
        $this->pdo()->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);

        self::assertSame([1, 2, 3, 4, 5], iterator_to_array(UnbufferedQuery::rows($this->pdo(), self::SQL, [1], static fn (array $row): int => (int) $row['n']), false));
        self::assertFalse((bool) $this->pdo()->getAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY));
    }
}
