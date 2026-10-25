<?php
// Testdatei mit Dummy-Werten: Transport smtp nur mit den Pflichtschlüsseln
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
            'username' => 'feuerwehr-app@prodad.de',
            'password' => 'smtp-pass-nicht-ausgeben',
        ],
    ],
];
