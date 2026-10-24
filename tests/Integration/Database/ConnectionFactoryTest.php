<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Database;

use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\ConnectionFactory;
use FirefightingEquipment\Database\DatabaseConfig;
use FirefightingEquipment\Tests\Integration\IntegrationTestCase;
use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ConnectionFactory::class)]
final class ConnectionFactoryTest extends IntegrationTestCase
{
    public function testConnectionWorksAndFetchesAssociative(): void
    {
        $row = $this->pdo()->query('SELECT 1 AS one')->fetch();

        self::assertSame(['one' => 1], $row);
    }

    public function testServerIsMariaDb1011(): void
    {
        $version = $this->pdo()->query('SELECT VERSION()')->fetchColumn();

        self::assertIsString($version);
        self::assertStringContainsString('MariaDB', $version);
        self::assertStringStartsWith('10.11.', $version);
    }

    public function testConnectionUsesUtf8mb4UnicodeCi(): void
    {
        $row = $this->pdo()->query(
            'SELECT @@character_set_client AS client, @@character_set_connection AS connection,'
            . ' @@character_set_results AS results, @@collation_connection AS collation',
        )->fetch();

        self::assertSame([
            'client' => 'utf8mb4',
            'connection' => 'utf8mb4',
            'results' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ], $row);
    }

    public function testSessionTimeZoneIsUtc(): void
    {
        self::assertSame('+00:00', $this->pdo()->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testSqlModeIsSetCompletely(): void
    {
        $mode = $this->pdo()->query('SELECT @@session.sql_mode')->fetchColumn();
        self::assertIsString($mode);

        $actual = explode(',', $mode);
        $expected = explode(',', ConnectionFactory::SQL_MODE);
        sort($actual);
        sort($expected);
        self::assertSame($expected, $actual);
    }

    public function testTooLongValueFailsInsteadOfBeingTruncated(): void
    {
        $pdo = $this->pdo();
        $pdo->exec(
            'CREATE TEMPORARY TABLE fe_tmp_strict (id INT AUTO_INCREMENT PRIMARY KEY, text VARCHAR(5) NOT NULL)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        try {
            $pdo->prepare('INSERT INTO fe_tmp_strict (text) VALUES (?)')->execute(['zu lang']);
            self::fail('PDOException erwartet.');
        } catch (\PDOException $e) {
            // 1406: Data too long for column
            self::assertSame(1406, $e->errorInfo[1] ?? null);
        }
        self::assertSame(0, $pdo->query('SELECT COUNT(*) FROM fe_tmp_strict')->fetchColumn());
    }

    public function testPreparesAreNotEmulated(): void
    {
        self::assertFalse($this->pdo()->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }

    public function testErrorsAreThrownAsExceptions(): void
    {
        $this->expectException(\PDOException::class);
        $this->pdo()->query('SELECT * FROM fe_table_does_not_exist');
    }

    public function testUmlautsAndEmojiSurviveWriteAndRead(): void
    {
        $text = 'Gerätehaus Süd – ÄÖÜäöüß 🚒🧯';
        $pdo = $this->pdo();
        $pdo->exec(
            'CREATE TEMPORARY TABLE fe_tmp_roundtrip (id INT AUTO_INCREMENT PRIMARY KEY, text VARCHAR(100) NOT NULL)'
            . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->prepare('INSERT INTO fe_tmp_roundtrip (text) VALUES (?)')->execute([$text]);
        $row = $pdo->query('SELECT text, CHAR_LENGTH(text) AS chars, LENGTH(text) AS bytes FROM fe_tmp_roundtrip')
            ->fetch();

        self::assertSame($text, $row['text']);
        // Gleiche Zeichen- und Bytezahl wie in PHP schließt eine Doppelkodierung aus.
        self::assertSame(mb_strlen($text), $row['chars']);
        self::assertSame(strlen($text), $row['bytes']);
    }

    public function testWrongPasswordThrowsExceptionWithoutCredentials(): void
    {
        $valid = TestDatabase::config();
        $wrongPass = 'falsches-passwort-nicht-ausgeben';
        $config = new DatabaseConfig($valid->host, $valid->name, $valid->user, $wrongPass);

        try {
            new ConnectionFactory()->create($config);
            self::fail('ConnectionException erwartet.');
        } catch (ConnectionException $e) {
            self::assertStringNotContainsString($wrongPass, $e->getMessage());
            self::assertStringNotContainsString($valid->user, $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }
}
