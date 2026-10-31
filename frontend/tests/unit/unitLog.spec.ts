// @vitest-environment node
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { UNIT_LOG_DIR, unitLogEntry, type VitestJsonReport } from './support/unitLog';

const ROOT = '/repo/frontend';
const AT = new Date('2026-10-31T13:05:09.123Z');

function report(overrides: Partial<VitestJsonReport> = {}): VitestJsonReport {
    return {
        numTotalTests: 3,
        numFailedTests: 0,
        testResults: [
            {
                name: '/repo/frontend/tests/unit/a.spec.ts',
                status: 'passed',
                assertionResults: [
                    { ancestorTitles: [], title: 'A eins', status: 'passed' },
                    { ancestorTitles: [], title: 'A zwei', status: 'passed' },
                ],
            },
            {
                name: '/repo/frontend/tests/unit/b.spec.ts',
                status: 'passed',
                assertionResults: [{ ancestorTitles: [], title: 'B eins', status: 'passed' }],
            },
        ],
        ...overrides,
    };
}

describe('unitLogEntry (npm run test:unit:log)', () => {
    it('grüner Lauf: eine Zeile mit Zeit in UTC und Anzahl', () => {
        expect(unitLogEntry(report(), 0, AT, ROOT)).toBe(
            '2026-10-31 13:05:09Z  grün  3 Tests in 2 Dateien\n',
        );
    });

    it('roter Lauf: Name jedes gescheiterten Tests mit Datei und erster Zeile der Meldung', () => {
        const failed = report({
            numFailedTests: 1,
            testResults: [
                {
                    name: '/repo/frontend/tests/unit/a.spec.ts',
                    status: 'failed',
                    assertionResults: [
                        { ancestorTitles: [], title: 'A eins', status: 'passed' },
                        {
                            ancestorTitles: ['Gruppe'],
                            title: 'A zwei',
                            status: 'failed',
                            failureMessages: [
                                '\u001b[31mAssertionError: expected 1 to be 2\u001b[39m\n    at x.ts:1',
                            ],
                        },
                    ],
                },
            ],
        });
        expect(unitLogEntry(failed, 1, AT, ROOT)).toBe(
            '2026-10-31 13:05:09Z  ROT  1 von 3 Tests gescheitert (1 Dateien, Exit-Code 1)\n' +
                '    FAIL tests/unit/a.spec.ts > Gruppe > A zwei: AssertionError: expected 1 to be 2\n',
        );
    });

    it('Datei scheitert ohne einzelne Tests (Import): die Datei mit Meldung', () => {
        const broken = report({
            numTotalTests: 0,
            testResults: [
                {
                    name: '/repo/frontend/tests/unit/c.spec.ts',
                    status: 'failed',
                    message: 'Failed to load url ./fehlt',
                    assertionResults: [],
                },
            ],
        });
        expect(unitLogEntry(broken, 1, AT, ROOT)).toContain(
            '    FAIL tests/unit/c.spec.ts: Failed to load url ./fehlt\n',
        );
    });

    it('ohne Bericht (Vitest abgebrochen): Exit-Code', () => {
        expect(unitLogEntry(null, 2, AT, ROOT)).toBe(
            '2026-10-31 13:05:09Z  ROT  ohne Bericht von Vitest (Exit-Code 2)\n',
        );
    });

    it('Ordner des Protokolls ist in .gitignore', () => {
        const ignore = readFileSync(
            fileURLToPath(new URL('../../.gitignore', import.meta.url)),
            'utf8',
        );
        expect(ignore.split('\n')).toContain(`${UNIT_LOG_DIR}/`);
    });
});
