<?php

declare(strict_types=1);

use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\Request;

// Router für den Benutzertest (nur lokal, php -S): wie public/index.php, aber gegen die TESTDATENBANK
// (config/config.test.php, Endung _test) mit erfundenen Daten. Der Origin-Header wird entfernt, damit die
// Konfiguration der Testdatenbank keine erlaubten Origins braucht. Kein App-Code wird geändert.
ini_set('display_errors', '0');
unset($_SERVER['HTTP_ORIGIN']);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

Application::create(dirname(__DIR__, 4) . '/config/config.test.php')
    ->handle(Request::fromGlobals())
    ->send();
