<?php

declare(strict_types=1);

use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Database\ConnectionFactory;

// Benutzertest: Aufbau der Testdatenbank (nur config/config.test.php, Endung _test, erfundene Daten).
//   php seed.php reset   leert alle Tabellen fe_*, legt die Stadt "test" (Feuerwehr Musterstadt), Super-Admin
//                         und Admin an und spielt die Testdaten der Test-Stadt ein
//   php seed.php users    legt die übrigen Konten an (nach den Rollen aus seed.mjs)
// Alle Konten haben das erfundene Passwort PASSWORD.

const PASSWORD = 'Muster-Passwort-2026';

$root = dirname(__DIR__, 3);
$root = realpath($root);
require $root . '/vendor/autoload.php';
require $root . '/tests/Support/TestDatabase.php';

$config = $root . '/config/config.test.php';
$pdo = (new ConnectionFactory())->create((new ConfigLoader())->loadDatabaseConfig($config));
\FirefightingEquipment\Tests\Support\TestDatabase::assertConnectedToTestDatabase($pdo);
$hash = password_hash(PASSWORD, PASSWORD_ARGON2ID);

function run(string $cmd): void
{
    passthru($cmd, $code);
    if ($code !== 0) {
        fwrite(STDERR, "Fehler ($code): $cmd\n");
        exit(1);
    }
}

function user(PDO $pdo, string $hash, int $tenantId, string $email, string $name, string $role, int $roleId, string $status = 'active', ?int $code = null): void
{
    $pdo->prepare(
        'INSERT INTO fe_users (tenant_id, role, role_id, status, email, email_verified_at, password_hash, name, display_name, invitation_code_id)'
        . ' VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)',
    )->execute([$tenantId, $role, $roleId, $status, $email, $hash, $name, $name, $code]);
}

$mode = $argv[1] ?? '';
if ($mode === 'reset') {
    \FirefightingEquipment\Tests\Support\TestDatabase::clearDomainTables($pdo);
    $cfg = escapeshellarg('--config=' . $config);
    run('php ' . escapeshellarg($root . '/bin/tenant.php') . ' create --slug=test --name="Feuerwehr Musterstadt" ' . $cfg);
    $tid = (int) $pdo->query("SELECT id FROM fe_tenants WHERE slug = 'test'")->fetchColumn();
    $adminRole = (int) $pdo->query("SELECT id FROM fe_roles WHERE tenant_id = $tid AND system_key = 'admin'")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO fe_users (tenant_id, role, status, email, email_verified_at, password_hash, name, display_name)"
        . " VALUES (NULL, 'superadmin', 'active', 'super@example.org', UTC_TIMESTAMP(), ?, 'Super Beispiel', 'Super Beispiel')",
    )->execute([$hash]);
    user($pdo, $hash, $tid, 'anna.beispiel@example.org', 'Anna Beispiel', 'admin', $adminRole);
    run('php ' . escapeshellarg($root . '/bin/seed-test-city.php') . ' --slug=test --execute ' . $cfg);
    echo "reset ok\n";
} elseif ($mode === 'users') {
    $tid = (int) $pdo->query("SELECT id FROM fe_tenants WHERE slug = 'test'")->fetchColumn();
    $role = static fn (string $name): int => (int) $pdo->query('SELECT id FROM fe_roles WHERE tenant_id = ' . $tid . ' AND name = ' . $pdo->quote($name))->fetchColumn();
    user($pdo, $hash, $tid, 'max.muster@example.org', 'Max Muster', 'user', $role('Gerätewart'));
    user($pdo, $hash, $tid, 'lena.probe@example.org', 'Lena Probe', 'user', $role('Geräteträger'));
    user($pdo, $hash, $tid, 'erika.probe@example.org', 'Erika Probe', 'user', $role('Benutzer'), 'pending', (int) $pdo->query('SELECT id FROM fe_invitation_codes WHERE tenant_id = ' . $tid . ' ORDER BY id LIMIT 1')->fetchColumn());
    user($pdo, $hash, $tid, 'otto.austritt@example.org', 'Otto Austritt', 'user', $role('Benutzer'));
    user($pdo, $hash, $tid, 'karl.alt@example.org', 'Karl Alt', 'user', $role('Benutzer'));
    echo "users ok\n";
} else {
    fwrite(STDERR, "Aufruf: php seed.php reset|users\n");
    exit(2);
}
