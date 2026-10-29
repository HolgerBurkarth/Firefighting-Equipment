import { spawnSync } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Lastläufe der Browser-Tests (`npm run test:load -- [--runs=N] [Playwright-Argumente]`), z. B.
 * `npm run test:load -- tests/e2e/appUpdate.spec.ts --project=webkit --runs=3`. Alle fünf Profile
 * (playwright.all.config.ts), ohne `--repeat-each` jeder Test 10-mal. Jeder Durchgang schreibt in
 * einen eigenen Ordner test-results/load/<Zeit>-<n>, damit die Traces gescheiterter Tests bleiben.
 * Sporadische Fehler so unter Last suchen, nie mit längeren Wartezeiten überdecken.
 */
const DEFAULT_REPEAT_EACH = 10;

const frontend = fileURLToPath(new URL('../../../', import.meta.url));
const cli = join(frontend, 'node_modules', '@playwright', 'test', 'cli.js');

const args = process.argv.slice(2);
const runsArg = args.find((arg) => arg.startsWith('--runs='));
const runs = runsArg === undefined ? 1 : Number(runsArg.slice('--runs='.length));
if (!Number.isInteger(runs) || runs < 1) {
    process.stderr.write('--runs=N erwartet eine positive Ganzzahl.\n');
    process.exit(2);
}
const playwrightArgs = args.filter((arg) => arg !== runsArg);
if (!playwrightArgs.some((arg) => arg.startsWith('--repeat-each'))) {
    playwrightArgs.push(`--repeat-each=${String(DEFAULT_REPEAT_EACH)}`);
}

const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\..*$/, 'Z');
const results: string[] = [];
for (let run = 1; run <= runs; run += 1) {
    const output = join('test-results', 'load', `${stamp}-${String(run)}`);
    const started = Date.now();
    const result = spawnSync(
        process.execPath,
        [cli, 'test', '--config=playwright.all.config.ts', `--output=${output}`, ...playwrightArgs],
        { cwd: frontend, stdio: 'inherit' },
    );
    const seconds = Math.round((Date.now() - started) / 1000);
    results.push(
        `Durchgang ${String(run)}: ${result.status === 0 ? 'grün' : 'ROT'} in ${String(seconds)} s, Ausgabe ${output}`,
    );
}
process.stdout.write(`\n${results.join('\n')}\n`);
process.exit(results.some((line) => line.includes('ROT')) ? 1 : 0);
