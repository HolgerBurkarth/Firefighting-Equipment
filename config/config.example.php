<?php
// Vorlage für die Zugangsdaten. Kopie anlegen als:
//   config.local.php – Zugang der Anwendung (auf dem Server: Produktiv-DB)
//   config.test.php  – Zugang nur für Tests, nur lokal; Datenbankname muss auf _test enden
// Beide Kopien werden nie committet.
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'DATENBANKNAME',
        'user' => 'BENUTZER',
        'pass' => 'PASSWORT',
    ],
    // Optional, nur lokal: weitere Origins für ändernde API-Anfragen (https://www.prodad.de ist immer
    // erlaubt). Auf dem Server weglassen.
    // 'http' => [
    //     'allowed_origins' => ['http://localhost:8000'],
    // ],
];
