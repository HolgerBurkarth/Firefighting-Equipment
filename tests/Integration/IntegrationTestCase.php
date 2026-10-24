<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration;

use FirefightingEquipment\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Basis für Tests gegen die Testdatenbank. Jeder Test erhält eine eigene Verbindung,
 * temporäre Tabellen verschwinden daher nach dem Test.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        if (!TestDatabase::configExists()) {
            self::markTestSkipped('config/config.test.php fehlt – Integrationstests übersprungen.');
        }

        $this->pdo = TestDatabase::connect();
    }

    protected function tearDown(): void
    {
        $this->pdo = null;
    }

    protected function hasConnection(): bool
    {
        return $this->pdo !== null;
    }

    protected function pdo(): \PDO
    {
        return $this->pdo ?? throw new \LogicException('Keine Datenbankverbindung.');
    }
}
