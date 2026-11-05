<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Api;

use FirefightingEquipment\Api\V1\Roles\RoleJson;
use FirefightingEquipment\Permission\Action;
use FirefightingEquipment\Permission\ActionGroup;
use FirefightingEquipment\Permission\Area;
use FirefightingEquipment\Permission\AreaLevel;
use FirefightingEquipment\Permission\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * tests/Fixtures/permission-catalog.json ist genau GET permission-catalog (RoleJson::catalog); das
 * Frontend prüft damit Parser, Editor und Browser-Tests. Ändert sich der Katalog, die Datei neu
 * schreiben (Inhalt der Meldung).
 */
#[CoversClass(RoleJson::class)]
#[UsesClass(Action::class)]
#[UsesClass(ActionGroup::class)]
#[UsesClass(Area::class)]
#[UsesClass(AreaLevel::class)]
#[UsesClass(Permissions::class)]
final class PermissionCatalogFixtureTest extends TestCase
{
    public function testFixtureEqualsCatalog(): void
    {
        $file = dirname(__DIR__, 2) . '/Fixtures/permission-catalog.json';
        $expected = json_encode(RoleJson::catalog(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertSame(
            json_decode($expected, true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR),
            "permission-catalog.json passt nicht zu RoleJson::catalog():\n" . $expected,
        );
    }
}
