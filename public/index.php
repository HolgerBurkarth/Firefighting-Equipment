<?php

declare(strict_types=1);

use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\Request;

// Fehlertexte nie in die Antwort schreiben; Details stehen im Server-Log.
ini_set('display_errors', '0');

require dirname(__DIR__) . '/vendor/autoload.php';

// Front-Controller, auch für lokal: php -S localhost:8000 public/index.php
Application::create(dirname(__DIR__) . '/config/config.local.php')
    ->handle(Request::fromGlobals())
    ->send();
