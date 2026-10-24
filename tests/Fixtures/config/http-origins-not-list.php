<?php
// Testdatei mit Dummy-Werten: allowed_origins ist keine Liste
return [
    'db' => [
        'host' => 'host-nicht-ausgeben',
        'name' => 'name-nicht-ausgeben',
        'user' => 'user-nicht-ausgeben',
        'pass' => 'pass-nicht-ausgeben',
    ],
    'http' => [
        'allowed_origins' => ['lokal' => 'http://localhost:8000'],
    ],
];
