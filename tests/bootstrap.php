<?php

declare(strict_types=1);

use FirefightingEquipment\Tests\Support\TestDatabase;

require dirname(__DIR__) . '/vendor/autoload.php';

// Schutz vor Tests gegen eine echte Datenbank: Ist eine Testkonfiguration vorhanden,
// muss sie gültig sein und auf eine Datenbank mit Endung _test zeigen. Sonst sofort abbrechen.
if (TestDatabase::configExists()) {
    try {
        TestDatabase::config();
    } catch (\Throwable $e) {
        fwrite(STDERR, PHP_EOL . 'ABBRUCH: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
