<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Import;

use FirefightingEquipment\Device\Import\DeviceImport;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Der CSV-Import schreibt state_changed_at und state_version wie die Einzelbearbeitung: neue Geräte
 * Version 1 und Serverzeit, geänderter Standort oder Status neu, sonst unverändert.
 */
#[CoversClass(DeviceImport::class)]
final class DeviceImportStateColumnsTest extends ImportApiTestCase
{
    public function testNewAndChangedDevices(): void
    {
        $moved = $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'MA-01', 'version' => 3]);
        $noted = $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'MA-02', 'version' => 2]);
        $this->pdo()->exec("UPDATE fe_devices SET state_changed_at = '2020-01-01 00:00:00', state_version = 1");

        $this->import(self::csv([
            ['MA-01', 'Atemschutzmaske', 'HLF 20 / G1', 'defekt', '', '', '', '', '', ''],
            ['MA-02', 'Atemschutzmaske', 'Lager', 'einsatzbereit', '', '', '', '', '', 'Notiz'],
            ['MA-03', 'Atemschutzmaske', 'Lager', '', '', '', '', '', '', ''],
        ]));

        $row = $this->deviceRow($moved);
        self::assertSame([4, 4], [$row['state_version'], $row['version']]);
        self::assertNotSame('2020-01-01 00:00:00', $row['state_changed_at']);

        $row = $this->deviceRow($noted);
        self::assertSame([1, 3], [$row['state_version'], $row['version']]);
        self::assertSame('2020-01-01 00:00:00', $row['state_changed_at']);

        $row = $this->deviceRow($this->deviceIdOf('MA-03'));
        self::assertSame([1, 1], [$row['state_version'], $row['version']]);
        self::assertNotSame('2020-01-01 00:00:00', $row['state_changed_at']);
    }
}
