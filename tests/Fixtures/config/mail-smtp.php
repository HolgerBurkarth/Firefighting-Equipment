<?php
// Testdatei mit Dummy-Werten: Transport smtp mit allen Schlüsseln
return [
    'db' => [
        'host' => 'host-nicht-ausgeben',
        'name' => 'name-nicht-ausgeben',
        'user' => 'user-nicht-ausgeben',
        'pass' => 'pass-nicht-ausgeben',
    ],
    'mail' => [
        'transport' => 'smtp',
        'smtp' => [
            'host' => 'mail.example.org',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => 'feuerwehr-app@prodad.de',
            'password' => 'smtp-pass-nicht-ausgeben',
            'timeout' => 2.5,
        ],
    ],
];
