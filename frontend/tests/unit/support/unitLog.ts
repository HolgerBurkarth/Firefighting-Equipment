import { spawnSync } from 'node:child_process';
import { appendFileSync, existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

/**
 * Unit-Tests mit Protokoll (`npm run test:unit:log -- [Vitest-Argumente]`): Vitest läuft wie bei
 * `npm run test:unit`, danach hängt das Skript je Lauf einen Eintrag an test-logs/unit.log an (Zeit
 * in UTC, Ergebnis und die Namen gescheiterter Tests mit der ersten Zeile der Meldung). Der Ordner
 * ist in .gitignore; anders als test-results/ leert ihn kein Playwright-Lauf. Fehler, die sich nicht
 * wiederholen lassen, trägt der Agent dort zusätzlich mit Datum ein (CLAUDE.md).
 */
export const UNIT_LOG_DIR = 'test-logs';
export const UNIT_LOG_FILE = 'unit.log';

/** Ausschnitt des JSON-Berichts von Vitest (Reporter `json`). */
export interface VitestJsonReport {
    readonly numTotalTests: number;
    readonly numFailedTests: number;
    readonly testResults: readonly {
        readonly name: string;
        readonly status: string;
        readonly message?: string;
        readonly assertionResults: readonly {
            /** Namen der umgebenden describe-Blöcke, außen zuerst. */
            readonly ancestorTitles: readonly string[];
            readonly title: string;
            readonly status: string;
            readonly failureMessages?: readonly string[];
        }[];
    }[];
}

/** Erste nicht leere Zeile, ohne Farbcodes, höchstens 200 Zeichen. */
function firstLine(text: string | undefined): string {
    const line = (text ?? '')
        .replace(/\u001b\[[0-9;]*m/g, '')
        .split('\n')
        .map((part) => part.trim())
        .find((part) => part !== '');
    return (line ?? '').slice(0, 200);
}

/**
 * Eintrag für unit.log. `report` null: Vitest hat keinen Bericht geschrieben (Abbruch).
 * `root`: Ordner, zu dem die Pfade der Testdateien relativ angegeben werden.
 */
export function unitLogEntry(
    report: VitestJsonReport | null,
    exitCode: number,
    at: Date,
    root: string,
): string {
    const stamp = `${at.toISOString().slice(0, 19).replace('T', ' ')}Z`;
    if (report === null) {
        return `${stamp}  ROT  ohne Bericht von Vitest (Exit-Code ${String(exitCode)})\n`;
    }
    const failures: string[] = [];
    for (const file of report.testResults) {
        const path = relative(root, file.name).replace(/\\/g, '/');
        const failed = file.assertionResults.filter((test) => test.status === 'failed');
        for (const test of failed) {
            failures.push(
                `    FAIL ${[path, ...test.ancestorTitles, test.title].join(' > ')}: ${firstLine(test.failureMessages?.[0])}`,
            );
        }
        // Datei ohne einzelne Tests gescheitert (z. B. Fehler beim Import).
        if (file.status === 'failed' && failed.length === 0) {
            failures.push(`    FAIL ${path}: ${firstLine(file.message)}`);
        }
    }
    const files = `${String(report.testResults.length)} Dateien`;
    if (exitCode === 0 && failures.length === 0) {
        return `${stamp}  grün  ${String(report.numTotalTests)} Tests in ${files}\n`;
    }
    const summary = `${String(report.numFailedTests)} von ${String(report.numTotalTests)} Tests gescheitert (${files}, Exit-Code ${String(exitCode)})`;
    return `${stamp}  ROT  ${summary}\n${failures.map((line) => `${line}\n`).join('')}`;
}

const script = process.argv[1];
if (script !== undefined && import.meta.url === pathToFileURL(script).href) {
    const frontend = fileURLToPath(new URL('../../../', import.meta.url));
    const dir = join(frontend, UNIT_LOG_DIR);
    const reportFile = join(dir, 'unit-last.json');
    mkdirSync(dir, { recursive: true });
    rmSync(reportFile, { force: true });
    const cli = join(frontend, 'node_modules', 'vitest', 'vitest.mjs');
    const result = spawnSync(
        process.execPath,
        [
            cli,
            'run',
            '--reporter=default',
            '--reporter=json',
            `--outputFile.json=${reportFile}`,
            ...process.argv.slice(2),
        ],
        { cwd: frontend, stdio: 'inherit' },
    );
    const exitCode = result.status ?? 1;
    const report = existsSync(reportFile)
        ? (JSON.parse(readFileSync(reportFile, 'utf8')) as VitestJsonReport)
        : null;
    const entry = unitLogEntry(report, exitCode, new Date(), frontend);
    appendFileSync(join(dir, UNIT_LOG_FILE), entry);
    process.stdout.write(`\nProtokoll (${UNIT_LOG_DIR}/${UNIT_LOG_FILE}):\n${entry}`);
    process.exit(exitCode);
}
