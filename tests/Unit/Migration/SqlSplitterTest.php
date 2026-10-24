<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Migration;

use FirefightingEquipment\Migration\MigrationException;
use FirefightingEquipment\Migration\SqlSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SqlSplitter::class)]
#[UsesClass(MigrationException::class)]
final class SqlSplitterTest extends TestCase
{
    public function testSplitsAtSemicolon(): void
    {
        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2;\n"));
    }

    public function testLastStatementWithoutSemicolonIsKept(): void
    {
        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2\n"));
    }

    public function testEmptyStatementsAndWhitespaceAreDropped(): void
    {
        self::assertSame(['SELECT 1'], SqlSplitter::split(" ;\n;SELECT 1;;  \n"));
        self::assertSame([], SqlSplitter::split("  \n\t"));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function semicolonsThatDoNotSplit(): iterable
    {
        yield 'einfache Anführungszeichen' => ["INSERT INTO t VALUES ('a;b');", ["INSERT INTO t VALUES ('a;b')"]];
        yield 'doppelte Anführungszeichen' => ['INSERT INTO t VALUES ("a;b");', ['INSERT INTO t VALUES ("a;b")']];
        yield 'Backticks' => ['SELECT `a;b` FROM t;', ['SELECT `a;b` FROM t']];
        yield 'verdoppeltes Anführungszeichen' => ["SELECT 'it''s; ok';", ["SELECT 'it''s; ok'"]];
        yield 'Backslash-Escape' => ["SELECT 'a\\';b';", ["SELECT 'a\\';b'"]];
        yield 'Zeilenkommentar --' => ["SELECT 1 -- a;b\n;", ['SELECT 1 -- a;b']];
        yield 'Zeilenkommentar #' => ["SELECT 1 # a;b\n;", ['SELECT 1 # a;b']];
        yield 'Blockkommentar' => ['SELECT /* a;b */ 1;', ['SELECT /* a;b */ 1']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('semicolonsThatDoNotSplit')]
    public function testSemicolonInStringsAndCommentsDoesNotSplit(string $sql, array $expected): void
    {
        self::assertSame($expected, SqlSplitter::split($sql));
    }

    public function testDoubleDashWithoutSpaceIsNoComment(): void
    {
        self::assertSame(['SELECT 1--1', 'SELECT 2'], SqlSplitter::split('SELECT 1--1; SELECT 2;'));
    }

    public function testCommentOnlyPartsAreDropped(): void
    {
        $sql = "-- Kopf\n/* Block */\nSELECT 1;\n-- Ende\n# Ende\n/* Ende */\n";

        self::assertSame(["-- Kopf\n/* Block */\nSELECT 1"], SqlSplitter::split($sql));
    }

    public function testExecutableCommentCountsAsCode(): void
    {
        self::assertSame(['/*!40101 SET NAMES utf8mb4 */'], SqlSplitter::split('/*!40101 SET NAMES utf8mb4 */;'));
        self::assertSame(['/*M!100100 SELECT 1 */'], SqlSplitter::split('/*M!100100 SELECT 1 */;'));
    }

    public function testUmlautsArePreserved(): void
    {
        self::assertSame(["INSERT INTO t VALUES ('Größe; Wärme')"], SqlSplitter::split("INSERT INTO t VALUES ('Größe; Wärme');"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSql(): iterable
    {
        yield 'offene Zeichenkette' => ["SELECT 'abc;", 'Nicht abgeschlossene Zeichenkette'];
        yield 'offener Backtick' => ['SELECT `abc;', 'Nicht abgeschlossene Zeichenkette'];
        yield 'offener Blockkommentar' => ['SELECT 1 /* abc;', 'Nicht abgeschlossener Kommentar'];
        yield 'DELIMITER' => ["DELIMITER //\nCREATE TRIGGER x;", 'DELIMITER'];
    }

    #[DataProvider('invalidSql')]
    public function testInvalidSqlThrows(string $sql, string $message): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage($message);

        SqlSplitter::split($sql);
    }
}
