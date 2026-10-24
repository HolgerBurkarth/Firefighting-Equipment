<?php
// Testdatei mit Dummy-Werten: host ist kein String
// Die Werte dürfen in keiner Fehlermeldung erscheinen.
return [
    'db' => [
        'host' => ['host-nicht-ausgeben'],
        'name' => 'name-nicht-ausgeben',
        'user' => 'user-nicht-ausgeben',
        'pass' => 'pass-nicht-ausgeben',
    ],
];
