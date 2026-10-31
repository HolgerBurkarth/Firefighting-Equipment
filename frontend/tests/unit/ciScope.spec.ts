// @vitest-environment node
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
    E2E_ALL,
    E2E_NONE,
    E2E_NOT_IMPORTED,
    E2E_SHARDS,
    EVERYTHING,
    PHP,
    ciScope,
    listedTests,
    shardCount,
} from '../../build/ciScope';

const root = fileURLToPath(new URL('../../../', import.meta.url));

function phpFilesIn(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return entry.name === 'Fixtures' ? [] : phpFilesIn(path);
        }
        return entry.name.endsWith('.php') ? [path] : [];
    });
}

describe('ciScope', () => {
    it.each([
        ['src/Device/DeviceRepository.php', true, 'none'],
        ['migrations/0029_x.sql', true, 'none'],
        ['bin/tenant.php', true, 'none'],
        ['scripts/deploy.sh', true, 'none'],
        ['config/config.example.php', true, 'none'],
        ['composer.lock', true, 'all'],
        ['tests/Unit/Http/RouterTest.php', true, 'none'],
        ['tests/Fixtures/migrations/0001_test.sql', true, 'none'],
        ['tests/Fixtures/identifier-key-cases.json', true, 'all'],
        ['src/Http/Frontend/ContentSecurityPolicy.php', true, 'all'],
        ['public/index.php', true, 'all'],
        ['frontend/src/pages/UsersPage.vue', false, 'all'],
        ['frontend/package-lock.json', false, 'all'],
        ['frontend/vite.config.ts', false, 'all'],
        ['frontend/index.html', true, 'all'],
        ['frontend/src/styles/tokens.css', true, 'all'],
        ['frontend/build/compress.ts', true, 'all'],
        ['frontend/build/fileNames.ts', false, 'all'],
        ['frontend/tests/e2e/users.spec.ts', false, 'changed'],
        ['frontend/tests/e2e/support/devices.ts', false, 'changed'],
        ['frontend/tests/e2e/globalSetup.ts', false, 'all'],
        ['frontend/tests/e2e/support/update-server.php', false, 'all'],
        ['frontend/tests/unit/users.spec.ts', false, 'none'],
        ['frontend/eslint.config.js', false, 'none'],
        ['frontend/vitest.config.ts', false, 'none'],
        ['docs/frontend/mockup/Main.png', false, 'none'],
        ['.github/dependabot.yml', false, 'none'],
    ] as const)('%s → PHP %s, Browser-Tests %s', (file, php, e2e) => {
        expect(ciScope([file])).toEqual({ php, e2e });
    });

    it.each([
        '.github/workflows/ci.yml',
        'frontend/playwright.config.ts',
        'frontend/playwright.all.config.ts',
        'frontend/build/ciScope.ts',
    ])('%s prüft alles', (file) => {
        expect(ciScope(['docs/x.txt', file])).toEqual({ php: true, e2e: 'all' });
    });

    it('nichts geändert: weder PHP- noch Browser-Tests', () => {
        expect(ciScope([])).toEqual({ php: false, e2e: 'none' });
    });

    it('der weiteste Bereich gewinnt, unabhängig von der Reihenfolge', () => {
        const files = ['frontend/tests/e2e/users.spec.ts', 'frontend/src/data/users.ts'];
        expect(ciScope(files)).toEqual({ php: false, e2e: 'all' });
        expect(ciScope([...files].reverse())).toEqual({ php: false, e2e: 'all' });
        expect(ciScope(['frontend/tests/unit/a.spec.ts', 'frontend/tests/e2e/a.spec.ts'])).toEqual({
            php: false,
            e2e: 'changed',
        });
    });

    it('genannte Dateien und Ordner gibt es', () => {
        for (const path of [...EVERYTHING, ...PHP, ...E2E_ALL, ...E2E_NONE, ...E2E_NOT_IMPORTED]) {
            expect(existsSync(join(root, path)), path).toBe(true);
        }
    });

    it('Dateien des Frontends, die PHP-Tests lesen, lösen die PHP-Tests aus', () => {
        const read = phpFilesIn(join(root, 'tests')).flatMap((file) =>
            [...readFileSync(file, 'utf8').matchAll(/FRONTEND \. '\/([^']+)'/g)].map(
                (match) => `frontend/${match[1] ?? ''}`,
            ),
        );
        expect(read.length).toBeGreaterThan(0);
        for (const file of read) {
            expect(ciScope([file]).php, file).toBe(true);
        }
    });

    it('Teile im Workflow kommen aus dem Job "changes"', () => {
        const workflow = readFileSync(join(root, '.github/workflows/ci.yml'), 'utf8');
        expect(workflow).toContain('shard: ${{ fromJSON(needs.changes.outputs.e2e_shards) }}');
        expect(workflow).toContain('node build/ciScope.ts');
    });
});

describe('shardCount', () => {
    it.each([
        [0, 1400, 0],
        [1, 1400, 1],
        [175, 1400, 1],
        [176, 1400, 2],
        [700, 1400, 4],
        [1400, 1400, E2E_SHARDS],
        [2000, 1400, E2E_SHARDS],
        [5, 0, 0],
    ])('%i von %i Tests → %i Teile', (affected, total, shards) => {
        expect(shardCount(affected, total)).toBe(shards);
    });
});

describe('listedTests', () => {
    it('zählt Tests in verschachtelten Gruppen, Dateien ohne Tests fallen weg', () => {
        const report = {
            errors: [],
            suites: [
                {
                    file: 'a.spec.ts',
                    specs: [{ tests: [1, 2] }],
                    suites: [{ file: 'a.spec.ts', specs: [{ tests: [1, 2, 3] }] }],
                },
                { file: 'b.spec.ts', specs: [], suites: [] },
                { file: 'c.spec.ts', suites: [{ file: 'c.spec.ts', specs: [{ tests: [1] }] }] },
            ],
        };
        expect(listedTests(report)).toEqual({ files: ['a.spec.ts', 'c.spec.ts'], tests: 6 });
    });

    it('Fehler beim Laden brechen ab', () => {
        expect(() => listedTests({ errors: [{ message: 'x' }], suites: [] })).toThrow(/Fehler/);
    });
});
