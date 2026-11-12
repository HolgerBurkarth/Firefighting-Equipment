// Setzt die Testumgebung des Benutzertests komplett zurück (Datenbank leeren, Daten, Rollen, Konten).
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const dir = fileURLToPath(new URL('.', import.meta.url));
const run = (cmd, args) => {
    const r = spawnSync(cmd, args, { stdio: 'inherit' });
    if (r.status !== 0) process.exit(r.status ?? 1);
};
run('php', [`${dir}seed.php`, 'reset']);
run('node', [`${dir}seed.mjs`]);
run('php', [`${dir}seed.php`, 'users']);
