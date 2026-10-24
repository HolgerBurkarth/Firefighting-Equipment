<?php

declare(strict_types=1);

use FirefightingEquipment\Cli\UserCommand;

// Nur auf der Kommandozeile ausführbar. Zusätzlich sperrt die .htaccess des Projekts
// alles außerhalb von public/.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$command = new UserCommand(
    dirname(__DIR__) . '/config/config.local.php',
    STDIN,
    STDOUT,
    STDERR,
);

exit($command->run(array_values(array_slice($argv, 1))));
