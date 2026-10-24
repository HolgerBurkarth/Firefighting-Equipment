<?php

// Ersatz für mysqldump in Tests: php fake-mysqldump.php MODUS [Argumente von DatabaseDumper ...]

declare(strict_types=1);

$mode = $argv[1] ?? '';
$args = array_slice($argv, 2);
$defaults = str_starts_with($args[0] ?? '', '--defaults-extra-file=')
    ? substr($args[0], strlen('--defaults-extra-file='))
    : '';

switch ($mode) {
    case 'complete':
        echo "-- MariaDB dump (Ersatz)\n";
        foreach ($args as $arg) {
            echo '-- ARG ', $arg, "\n";
        }
        echo '-- DEFAULTS ', is_file($defaults) ? 'vorhanden' : 'fehlt', "\n";
        echo "CREATE TABLE `fe_tenants` (id INT);\n";
        echo "INSERT INTO `fe_tenants` VALUES ('Müllheim');\n";
        echo "-- Dump completed on 2026-10-24  9:30:00\n";
        exit(0);

    case 'large':
        // Mehr als ein Pipe-Puffer, damit das stückweise Lesen geprüft wird.
        for ($i = 0; $i < 20000; $i++) {
            printf("INSERT INTO `fe_tenants` VALUES (%d, '%s');\n", $i, str_repeat('x', 100));
        }
        echo "-- Dump completed on 2026-10-24  9:30:00\n";
        exit(0);

    case 'incomplete':
        echo "-- MariaDB dump (Ersatz)\nCREATE TABLE `fe_tenants` (id INT);\n";
        exit(0);

    case 'fail':
        // Gibt absichtlich die Zugangsdatei aus, damit das Maskieren geprüft werden kann.
        echo "-- MariaDB dump (Ersatz)\n";
        fwrite(STDERR, "mysqldump: Got error: 1045: Access denied\n" . (string) file_get_contents($defaults));
        exit(2);

    default:
        fwrite(STDERR, "Unbekannter Modus: $mode\n");
        exit(99);
}
