<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Database;

use FirefightingEquipment\Database\DatabaseConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseConfig::class)]
final class DatabaseConfigTest extends TestCase
{
    public function testDebugOutputHidesPassword(): void
    {
        $config = new DatabaseConfig('localhost', 'beispiel_test', 'benutzer', 'pass-nicht-ausgeben');

        $output = print_r($config, true);

        self::assertStringNotContainsString('pass-nicht-ausgeben', $output);
        self::assertStringContainsString('beispiel_test', $output);
    }
}
