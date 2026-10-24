<?php
// Testdatei mit Dummy-Werten: erzeugt eine Ausgabe, die verworfen werden muss
// Die Werte dürfen in keiner Fehlermeldung erscheinen.
echo 'pass-nicht-ausgeben';

return [
    'db' => [
        'host' => 'host-nicht-ausgeben',
        'name' => 'name-nicht-ausgeben',
        'user' => 'user-nicht-ausgeben',
        'pass' => 'pass-nicht-ausgeben',
    ],
];
