<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Database;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionException;
use FirefightingEquipment\Database\LazyConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LazyConnection::class)]
#[UsesClass(ConfigException::class)]
#[UsesClass(ConfigLoader::class)]
final class LazyConnectionTest extends TestCase
{
    public function testFactoryIsNotCalledBeforeFirstUse(): void
    {
        $calls = 0;
        $connection = new LazyConnection(function () use (&$calls): \PDO {
            $calls++;
            throw new ConnectionException('nicht erwartet');
        });

        self::assertSame(0, $calls);
        self::assertFalse($connection->isConnected());
    }

    public function testFailedConnectIsNotCached(): void
    {
        $calls = 0;
        $connection = new LazyConnection(function () use (&$calls): \PDO {
            $calls++;
            throw new ConnectionException('weg');
        });

        foreach ([1, 2] as $expected) {
            try {
                $connection->get();
                self::fail('ConnectionException erwartet.');
            } catch (ConnectionException) {
                self::assertSame($expected, $calls);
            }
        }
        self::assertFalse($connection->isConnected());
    }

    public function testMissingConfigFileBecomesConnectionException(): void
    {
        $connection = LazyConnection::fromConfigFile(__DIR__ . '/gibt-es-nicht.php');

        try {
            $connection->get();
            self::fail('ConnectionException erwartet.');
        } catch (ConnectionException $e) {
            self::assertSame('Datenbank-Konfiguration nicht nutzbar.', $e->getMessage());
            self::assertInstanceOf(ConfigException::class, $e->getPrevious());
        }
    }
}
