<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Seed;

/**
 * Testdaten lassen sich nicht anlegen (z. B. Stadt nicht leer, kein Admin, Gerätetyp fehlt);
 * Meldung auf Deutsch für die Kommandozeile.
 */
final class TestCitySeedException extends \RuntimeException
{
}
