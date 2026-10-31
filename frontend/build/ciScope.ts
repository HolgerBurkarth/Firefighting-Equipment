import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

/**
 * Welche Jobs der CI ein Stand braucht (.github/workflows/ci.yml, Job "changes"; README "GitHub
 * Actions und Branch deploy"). Grundlage sind die geänderten Dateien seit einer Basis: bei einem
 * Push auf main der letzte Stand im Branch deploy, bei einem Pull Request dessen Basis. Lint,
 * Vitest und Build laufen immer; PHP-Tests und Browser-Tests nur, wenn ihr Bereich betroffen ist.
 * Der nächtliche Lauf (schedule) und Läufe von Hand prüfen immer alles.
 *
 * Aufruf (in frontend/, braucht node_modules): `node build/ciScope.ts <basis>` mit einem Commit oder
 * `all`; schreibt `php`, `e2e`, `e2e_files` und `e2e_shards` als `name=wert` auf stdout (für
 * $GITHUB_OUTPUT), eine Zusammenfassung auf stderr.
 */

/** Teile der Browser-Tests bei einem vollständigen Lauf (Matrix "shard" im Workflow). */
export const E2E_SHARDS = 8;

/** Browser-Tests: keine, nur die betroffenen Testdateien (`--only-changed`) oder alle. */
export type E2eScope = 'none' | 'changed' | 'all';

export interface CiScope {
    readonly php: boolean;
    readonly e2e: E2eScope;
}

/** Diese Dateien steuern den Ablauf selbst: jede Änderung prüft alles. */
export const EVERYTHING: readonly string[] = [
    '.github/workflows/',
    'frontend/playwright.config.ts',
    'frontend/playwright.all.config.ts',
    'frontend/build/ciScope.ts',
];

/**
 * PHP-Tests: PHP-Code, Migrationen, Kommandozeile, Skripte (Deploy, per PHPUnit geprüft), Vorlagen
 * der Konfiguration, Ressourcen, Composer und die Tests selbst (auch die gemeinsamen Fixtures in
 * tests/Fixtures/). Dazu die Dateien des Frontends, die PHP-Tests mit dem Backend abgleichen
 * (FrontendAssetsTest, ContentEncodingTest). README und CLAUDE.md (SlugRulesDocumentationTest)
 * zählen nicht, sonst liefe er bei fast jedem Schritt; dafür gilt die Regel in CLAUDE.md.
 */
export const PHP: readonly string[] = [
    'src/',
    'bin/',
    'migrations/',
    'tests/',
    'scripts/',
    'config/',
    'resources/',
    'public/',
    'composer.json',
    'composer.lock',
    'phpunit.xml',
    '.htaccess',
    '.gitattributes',
    'frontend/index.html',
    'frontend/build/appBase.ts',
    'frontend/build/buildInfo.ts',
    'frontend/build/compress.ts',
    'frontend/src/styles/tokens.css',
];

/**
 * Alle Browser-Tests: die Auslieferung durch PHP (src/Http/ mit CSP und Caching, public/, Composer)
 * und die gemeinsamen Fixtures. Im Frontend alles außer den Tests selbst und den Werkzeugen, die nur
 * Lint und Vitest betreffen (siehe E2E_NONE und e2eScopeOf).
 */
export const E2E_ALL: readonly string[] = [
    'src/Http/',
    'public/',
    'composer.json',
    'composer.lock',
    'frontend/',
];

/** Betreffen keine Browser-Tests (Vitest, ESLint, Prettier). */
export const E2E_NONE: readonly string[] = [
    'frontend/tests/unit/',
    'frontend/vitest.config.ts',
    'frontend/eslint.config.js',
    'frontend/.prettierrc.json',
    'frontend/.prettierignore',
];

/** Gemeinsame Testfälle von PHP und Frontend (tests/Fixtures/*.json, nicht in Unterordnern). */
const SHARED_FIXTURE = /^tests\/Fixtures\/[^/]+\.json$/;

const E2E_DIR = 'frontend/tests/e2e/';

/**
 * Dateien in tests/e2e/, die keine Testdatei importiert: `--only-changed` fände sie nicht. Das
 * Global-Setup (zweiter Build) und der Update-Testserver (PHP) wirken auf die Update-Tests.
 */
export const E2E_NOT_IMPORTED: readonly string[] = [
    'frontend/tests/e2e/globalSetup.ts',
    'frontend/tests/e2e/support/update-server.php',
];

function matches(file: string, patterns: readonly string[]): boolean {
    return patterns.some((pattern) =>
        pattern.endsWith('/') ? file.startsWith(pattern) : file === pattern,
    );
}

/**
 * Browser-Tests je Datei. Code der App können Playwright und `--only-changed` nicht zuordnen (die
 * Tests laden den Build, sie importieren die App nicht); jede Änderung daran prüft daher alle.
 * `--only-changed` gilt nur für TypeScript in tests/e2e/ (Testdateien und ihre Hilfen).
 */
function e2eScopeOf(file: string): E2eScope {
    if (file.startsWith(E2E_DIR)) {
        if (matches(file, E2E_NOT_IMPORTED) || !file.endsWith('.ts')) {
            return 'all';
        }
        return 'changed';
    }
    if (matches(file, E2E_NONE)) {
        return 'none';
    }
    if (matches(file, E2E_ALL) || SHARED_FIXTURE.test(file)) {
        return 'all';
    }
    return 'none';
}

const E2E_ORDER: readonly E2eScope[] = ['none', 'changed', 'all'];

/** Bereiche zu den geänderten Dateien (Pfade relativ zum Repository, mit `/`). */
export function ciScope(files: readonly string[]): CiScope {
    let php = false;
    let e2e: E2eScope = 'none';
    for (const file of files) {
        if (matches(file, EVERYTHING)) {
            return { php: true, e2e: 'all' };
        }
        php ||= matches(file, PHP);
        const scope = e2eScopeOf(file);
        if (E2E_ORDER.indexOf(scope) > E2E_ORDER.indexOf(e2e)) {
            e2e = scope;
        }
    }
    return { php, e2e };
}

/**
 * Teile für `affected` von `total` Tests: so viele, dass ein Teil höchstens so viele Tests hat wie
 * bei einem vollständigen Lauf, mindestens einer, höchstens E2E_SHARDS.
 */
export function shardCount(affected: number, total: number): number {
    if (affected <= 0 || total <= 0) {
        return 0;
    }
    return Math.min(E2E_SHARDS, Math.max(1, Math.ceil((E2E_SHARDS * affected) / total)));
}

interface ListedSpec {
    readonly tests: readonly unknown[];
}

interface ListedSuite {
    readonly file: string;
    readonly specs?: readonly ListedSpec[];
    readonly suites?: readonly ListedSuite[];
}

interface ListedReport {
    readonly suites: readonly ListedSuite[];
    readonly errors: readonly unknown[];
}

function countTests(suite: ListedSuite): number {
    const own = (suite.specs ?? []).reduce((sum, spec) => sum + spec.tests.length, 0);
    return (suite.suites ?? []).reduce((sum, child) => sum + countTests(child), own);
}

/** Testdateien (relativ zu tests/e2e/) und Anzahl der Tests aus `playwright test --list --reporter=json`. */
export function listedTests(report: ListedReport): { files: string[]; tests: number } {
    if (report.errors.length > 0) {
        throw new Error('Playwright meldet Fehler beim Laden der Tests.');
    }
    const files = report.suites.filter((suite) => countTests(suite) > 0).map((suite) => suite.file);
    const tests = report.suites.reduce((sum, suite) => sum + countTests(suite), 0);
    return { files, tests };
}

const frontend = fileURLToPath(new URL('../', import.meta.url));

function listTests(extraArgs: readonly string[]): { files: string[]; tests: number } {
    const cli = join(frontend, 'node_modules', '@playwright', 'test', 'cli.js');
    const output = execFileSync(
        process.execPath,
        [cli, 'test', '--list', '--reporter=json', ...extraArgs],
        { cwd: frontend, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 },
    );
    return listedTests(JSON.parse(output) as ListedReport);
}

function changedFiles(base: string): string[] {
    // Ohne Erkennung von Umbenennungen: Dann erscheinen alter und neuer Pfad.
    const output = execFileSync(
        'git',
        ['diff', '--name-only', '--no-renames', '-z', base, 'HEAD'],
        {
            cwd: frontend,
            encoding: 'utf8',
        },
    );
    return output.split('\0').filter((file) => file !== '');
}

function main(base: string): void {
    const scope = base === 'all' ? { php: true, e2e: 'all' as const } : ciScope(changedFiles(base));
    let files: string[] = [];
    let shards = 0;
    if (scope.e2e === 'all') {
        shards = E2E_SHARDS;
    } else if (scope.e2e === 'changed') {
        const affected = listTests([`--only-changed=${base}`]);
        const total = listTests([]);
        files = affected.files.map((file) => `tests/e2e/${file}`);
        shards = shardCount(affected.tests, total.tests);
        process.stderr.write(
            `Betroffen: ${affected.tests} von ${total.tests} Browser-Tests in ${files.length} Dateien.\n`,
        );
    }
    // Ohne Browser-Tests überspringt der Workflow den Job (e2e=false); die Matrix bleibt trotzdem
    // gültig (eine leere Liste wäre ein Fehler beim Auswerten).
    const shardList = Array.from({ length: Math.max(shards, 1) }, (_, index) => index + 1);
    process.stderr.write(
        `Basis ${base}: PHP-Tests ${scope.php ? 'ja' : 'nein'}, Browser-Tests ${scope.e2e}` +
            `${files.length > 0 ? ` (${files.join(' ')})` : ''}, Teile ${shards}.\n`,
    );
    process.stdout.write(
        [
            `php=${String(scope.php)}`,
            `e2e=${String(shards > 0)}`,
            `e2e_files=${files.join(' ')}`,
            `e2e_shards=${JSON.stringify(shardList)}`,
        ].join('\n') + '\n',
    );
}

const script = process.argv[1];
if (script !== undefined && import.meta.url === pathToFileURL(script).href) {
    const base = process.argv[2] ?? '';
    if (!/^(all|[0-9a-f]{7,40})$/.test(base)) {
        process.stderr.write('Aufruf: node build/ciScope.ts <commit|all>\n');
        process.exit(2);
    }
    main(base);
}
