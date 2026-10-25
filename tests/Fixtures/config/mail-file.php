<?php
// Testdatei mit Dummy-Werten: Transport file mit eigenem Ordner
return [
    'db' => [
        'host' => 'host-nicht-ausgeben',
        'name' => 'name-nicht-ausgeben',
        'user' => 'user-nicht-ausgeben',
        'pass' => 'pass-nicht-ausgeben',
    ],
    'mail' => [
        'transport' => 'file',
        'file_dir' => 'tmp/mails',
    ],
];
