import { readFile } from 'node:fs/promises';
import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { NO_DEVICES } from './support/devices';
import { horizontalOverflow } from './support/layout';
import { delegate, deviceTypeServer, locationServer } from './support/masterData';

/**
 * CSV-Import (`geraete/import`, KONZEPT.md, "Admin-Konsole – Entscheidungen", 32): Vorlage und
 * „Gültige Werte“ herunterladen, Datei wählen (Dateiauswahl des Browsers), Vorschau des Servers mit
 * Fehlern, Warnungen und „Meinten Sie …?“, Übernehmen samt 409 import_outdated. Der simulierte
 * Server beantwortet die Vorschau nach einer Liste (je Aufruf die nächste Antwort).
 */

const TOKEN = 'c'.repeat(64);
const CSV = '﻿Kennung;Gerätetyp;Standort\r\nM1;Atemschutzmaske;HLF20\r\n';

function summary(overrides: Record<string, number> = {}): Record<string, number> {
    return {
        rows: 2,
        new: 1,
        changed: 1,
        unchanged: 0,
        skipped: 0,
        errors: 0,
        warnings: 0,
        ...overrides,
    };
}

function row(line: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        line,
        identifier: `M${String(line - 1)}`,
        result: 'new',
        result_label: 'Neu',
        device: null,
        changes: [
            { field: 'identifier', label: 'Kennung', old: null, new: `M${String(line - 1)}` },
            { field: 'location', label: 'Standort', old: null, new: 'HLF 20 / G1' },
        ],
        messages: [],
        warnings: [],
        ...overrides,
    };
}

function preview(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        token: TOKEN,
        encoding: 'utf-8',
        delimiter: ';',
        delimiter_label: 'Semikolon',
        columns: ['Kennung', 'Gerätetyp', 'Standort'],
        summary: summary(),
        unknown: { device_types: [], locations: [] },
        suggestions: { device_types: [], locations: [] },
        rows: [
            row(2),
            row(3, {
                identifier: 'M2',
                result: 'changed',
                result_label: 'Änderungen',
                device: { id: 7, version: 2 },
                changes: [
                    { field: 'status', label: 'Status', old: 'einsatzbereit', new: 'defekt' },
                ],
            }),
        ],
        ...overrides,
    };
}

const WITH_ERRORS = preview({
    token: null,
    summary: summary({ rows: 3, new: 1, changed: 0, errors: 2, warnings: 1 }),
    unknown: { device_types: ['Atemschutzmaskee'], locations: ['HLF20', 'Wache Nord'] },
    suggestions: {
        device_types: [{ value: 'Atemschutzmaskee', suggestions: ['Atemschutzmaske'] }],
        locations: [{ value: 'HLF20', suggestions: ['HLF 20', 'HLF 20 / G1'] }],
    },
    rows: [
        row(2, {
            identifier: 'BEISPIEL-1',
            warnings: ['Die Kennung „BEISPIEL-1“ gehört zu den Beispielzeilen der Vorlage.'],
        }),
        row(3, {
            identifier: 'M2',
            result: 'error',
            result_label: 'Fehler',
            changes: [],
            messages: [
                'Der Gerätetyp „Atemschutzmaskee“ ist nicht bekannt. Meinten Sie „Atemschutzmaske“?',
            ],
        }),
        row(4, {
            identifier: 'M3',
            result: 'error',
            result_label: 'Fehler',
            changes: [],
            messages: ['Der Standort „Wache Nord“ ist nicht bekannt.'],
        }),
    ],
});

/** Vorschau und Übernehmen: je Aufruf die nächste Antwort der Listen (die letzte bleibt). */
function importServer(previews: readonly MockResponse[], applies: readonly MockResponse[] = []) {
    let previewCalls = 0;
    let applyCalls = 0;
    const next = (list: readonly MockResponse[], index: number): MockResponse =>
        list[Math.min(index, list.length - 1)] ?? { status: 500, body: {} };
    return {
        'POST api/v1/devices/import/preview': () => next(previews, previewCalls++),
        'POST api/v1/devices/import': () => next(applies, applyCalls++),
    } satisfies Record<string, (request: MockRequest) => MockResponse>;
}

let server = importServer([{ status: 200, body: preview() }]);

function useServer(previews: readonly MockResponse[], applies: readonly MockResponse[] = []): void {
    test.beforeEach(() => {
        server = importServer(previews, applies);
    });
}

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...NO_DEVICES,
        ...deviceTypeServer([{ id: 1, name: 'Atemschutzmaske', count: 0 }]),
        ...locationServer([{ id: 10, name: 'HLF 20', children: [{ id: 11, name: 'G1' }] }]).api,
        ...delegate(() => server),
        'GET api/v1/devices/import/template': {
            status: 200,
            contentType: 'text/csv; charset=utf-8',
            headers: { 'Content-Disposition': 'attachment; filename="geraete-vorlage-test.csv"' },
            text: '﻿Kennung;Scan-Code\r\nBEISPIEL-1;\r\n',
        },
        'GET api/v1/devices/import/values': {
            status: 200,
            contentType: 'text/csv; charset=utf-8',
            headers: {
                'Content-Disposition': 'attachment; filename="gueltige-werte-test-2026-10-30.csv"',
            },
            text: '﻿Spalte;Gültiger Wert\r\nGerätetyp;Atemschutzmaske\r\n',
        },
    },
});

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/geraete/import');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Geräte importieren (CSV)');
}

/** Datei über den Knopf und die Dateiauswahl des Browsers wählen. */
async function chooseFile(page: Page, content: string | Buffer = CSV, name = 'geraete.csv') {
    const chooser = page.waitForEvent('filechooser');
    await page.getByRole('button', { name: /Datei wählen/ }).click();
    await (
        await chooser
    ).setFiles({
        name,
        mimeType: 'text/csv',
        buffer: typeof content === 'string' ? Buffer.from(content, 'utf8') : content,
    });
}

function rowsShown(page: Page) {
    return page.getByTestId('import-rows').locator('.row-head .identifier');
}

function sent(requests: readonly MockRequest[], path: string): unknown[] {
    return requests.filter((r) => r.method === 'POST' && r.path === path).map((r) => r.body);
}

test.describe('Import-Seite', () => {
    useServer([{ status: 200, body: preview() }]);

    test('aus der Geräteliste, „Geräte“ bleibt im Menü hervorgehoben', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/geraete');
        await page.getByRole('link', { name: 'Import (CSV)' }).click();
        await expect(page).toHaveURL(/\/geraete\/import$/);
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Geräte', exact: true }),
        ).toHaveClass(/menu-link-active/);
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
    });

    test('Vorlage und gültige Werte herunterladen', async ({ page }) => {
        await open(page);
        const template = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Vorlage herunterladen' }).click();
        const templateFile = await template;
        expect(templateFile.suggestedFilename()).toBe('geraete-vorlage-test.csv');
        expect(await readFile(await templateFile.path(), 'utf8')).toBe(
            '﻿Kennung;Scan-Code\r\nBEISPIEL-1;\r\n',
        );

        const values = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Gültige Werte herunterladen' }).click();
        expect((await values).suggestedFilename()).toBe('gueltige-werte-test-2026-10-30.csv');
    });

    test('Datei prüfen und übernehmen', async ({ page, apiRequests }) => {
        server = importServer(
            [{ status: 200, body: preview() }],
            [{ status: 200, body: { summary: summary() } }],
        );
        await open(page);
        await chooseFile(page);

        // Gesendet wird die Datei unverändert als Base64.
        await expect(page.getByTestId('summary')).toContainText('2 Zeilen');
        expect(sent(apiRequests, 'api/v1/devices/import/preview')).toEqual([
            { file: Buffer.from(CSV, 'utf8').toString('base64') },
        ]);
        await expect(page.getByTestId('file-name')).toHaveText('geraete.csv');
        await expect(page.getByRole('heading', { name: '3. Vorschau' })).toBeFocused();
        await expect(page.getByText('Erkannt: UTF-8, Semikolon')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Alle (2)' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(rowsShown(page)).toHaveText(['M1', 'M2']);
        await expect(page.getByTestId('import-rows')).toContainText('Standort: HLF 20 / G1');
        await expect(page.getByTestId('import-rows')).toContainText(
            'Status: einsatzbereit → defekt',
        );

        await page.getByRole('button', { name: 'Geändert (1)' }).click();
        await expect(rowsShown(page)).toHaveText(['M2']);

        await page.getByLabel('Kommentar für den Verlauf (optional)').fill('Inventur 2026');
        await page.getByRole('button', { name: 'Import übernehmen (1 neu, 1 geändert)' }).click();

        await expect(page.getByTestId('imported')).toContainText(
            'Import übernommen: 1 neu, 1 geändert.',
        );
        expect(sent(apiRequests, 'api/v1/devices/import')).toEqual([
            {
                file: Buffer.from(CSV, 'utf8').toString('base64'),
                token: TOKEN,
                comment: 'Inventur 2026',
            },
        ]);
        await expect(page.getByTestId('preview')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'CSV-Datei wählen …' })).toBeVisible();
        await page.getByRole('link', { name: 'Zur Geräteliste' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
    });

    test('Datei über 2 MiB wird nicht gesendet', async ({ page, apiRequests }) => {
        await open(page);
        await chooseFile(page, Buffer.alloc(2_097_153, 0x41), 'gross.csv');
        await expect(page.getByRole('alert')).toHaveText(
            'Die Datei ist zu groß (2 MiB, höchstens 2 MiB). Bitte in mehrere Dateien aufteilen.',
        );
        expect(sent(apiRequests, 'api/v1/devices/import/preview')).toEqual([]);
    });
});

test.describe('Fehler in der Datei', () => {
    useServer([{ status: 200, body: WITH_ERRORS }]);

    test('zeigt Fehler zuerst, unbekannte Werte mit „Meinten Sie …?“, kein Übernehmen', async ({
        page,
    }) => {
        await open(page);
        await chooseFile(page);

        await expect(page.getByText('2 Zeilen enthalten Fehler.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Fehler (2)' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(rowsShown(page)).toHaveText(['M2', 'M3']);
        await expect(page.getByTestId('import-rows')).toContainText(
            'Der Gerätetyp „Atemschutzmaskee“ ist nicht bekannt. Meinten Sie „Atemschutzmaske“?',
        );
        await expect(page.getByTestId('unknown').locator('li')).toHaveText([
            'Gerätetyp „Atemschutzmaskee“: Meinten Sie „Atemschutzmaske“?',
            'Standort „HLF20“: Meinten Sie „HLF 20“ oder „HLF 20 / G1“?',
            'Standort „Wache Nord“',
        ]);
        await expect(page.getByRole('button', { name: /Import übernehmen/ })).toHaveCount(0);

        await page.getByRole('button', { name: 'Mit Warnung (1)' }).click();
        await expect(rowsShown(page)).toHaveText(['BEISPIEL-1']);
        await expect(page.getByTestId('import-rows')).toContainText(
            'Die Kennung „BEISPIEL-1“ gehört zu den Beispielzeilen der Vorlage.',
        );
    });
});

test.describe('Korrigierte Datei', () => {
    useServer([
        { status: 200, body: WITH_ERRORS },
        { status: 200, body: preview() },
    ]);

    test('dieselbe Datei nach dem Ändern erneut wählen', async ({ page, apiRequests }) => {
        await open(page);
        await chooseFile(page);
        await expect(page.getByText('2 Zeilen enthalten Fehler.')).toBeVisible();
        await chooseFile(page, `${CSV}M2;Atemschutzmaske;HLF 20\r\n`);
        await expect(page.getByRole('button', { name: /Import übernehmen/ })).toBeVisible();
        expect(sent(apiRequests, 'api/v1/devices/import/preview')).toHaveLength(2);
    });
});

test.describe('Seit der Vorschau geändert', () => {
    useServer(
        [
            { status: 200, body: preview() },
            { status: 200, body: preview({ token: 'd'.repeat(64) }) },
        ],
        [
            {
                status: 409,
                body: {
                    error: 'import_outdated',
                    message:
                        'Seit der Vorschau wurde etwas geändert. Bitte die Vorschau neu erstellen.',
                    details: { summary: summary() },
                },
            },
            { status: 200, body: { summary: summary() } },
        ],
    );
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

    test('prüft neu und übernimmt mit dem neuen Kennzeichen', async ({ page, apiRequests }) => {
        await open(page);
        await chooseFile(page);
        await page.getByRole('button', { name: /Import übernehmen/ }).click();

        await expect(page.getByText('Die Vorschau ist neu erstellt')).toBeVisible();
        expect(sent(apiRequests, 'api/v1/devices/import/preview')).toHaveLength(2);
        await page.getByRole('button', { name: /Import übernehmen/ }).click();
        await expect(page.getByTestId('imported')).toBeVisible();
        expect(
            sent(apiRequests, 'api/v1/devices/import').map(
                (body) => (body as { token: string }).token,
            ),
        ).toEqual([TOKEN, 'd'.repeat(64)]);
    });
});

test.describe('Datei nicht lesbar', () => {
    useServer([
        {
            status: 400,
            body: {
                error: 'invalid_request',
                message: 'Die Spalte „Kennung“ fehlt.',
                details: { line: 1 },
            },
        },
    ]);
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

    test('Meldung des Servers, keine Vorschau', async ({ page }) => {
        await open(page);
        await chooseFile(page);
        await expect(page.getByText('Die Spalte „Kennung“ fehlt.')).toBeVisible();
        await expect(page.getByTestId('preview')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Erneut prüfen' })).toBeEnabled();
    });
});

test.describe('Nichts zu ändern', () => {
    useServer([
        {
            status: 200,
            body: preview({
                summary: summary({ new: 0, changed: 0, unchanged: 2 }),
                rows: [
                    row(2, { result: 'unchanged', result_label: 'Keine Änderung', changes: [] }),
                    row(3, { result: 'unchanged', result_label: 'Keine Änderung', changes: [] }),
                ],
            }),
        },
    ]);

    test('kein Knopf zum Übernehmen', async ({ page }) => {
        await open(page);
        await chooseFile(page);
        await expect(page.getByTestId('nothing-to-do')).toBeVisible();
        await expect(page.getByRole('button', { name: /Import übernehmen/ })).toHaveCount(0);
    });
});

test.describe('Viele Zeilen', () => {
    useServer([
        {
            status: 200,
            body: preview({
                summary: summary({ rows: 150, new: 150, changed: 0 }),
                rows: Array.from({ length: 150 }, (_, i) => row(i + 2)),
            }),
        },
    ]);

    test('zeigt 100 und lädt weitere', async ({ page }) => {
        await open(page);
        await chooseFile(page);
        await expect(rowsShown(page)).toHaveCount(100);
        await page.getByRole('button', { name: 'Weitere anzeigen (100 von 150)' }).click();
        await expect(rowsShown(page)).toHaveCount(150);
        await expect(page.getByRole('button', { name: /Weitere anzeigen/ })).toHaveCount(0);
    });
});

test.describe('Handy', () => {
    useServer([{ status: 200, body: WITH_ERRORS }]);

    test('kein waagerechter Überstand, Knöpfe volle Breite', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        await chooseFile(page);
        await expect(page.getByTestId('summary')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
        const choose = await page.getByRole('button', { name: /Datei wählen/ }).boundingBox();
        const template = await page
            .getByRole('button', { name: 'Vorlage herunterladen' })
            .boundingBox();
        expect(choose?.width).toBe(template?.width);
    });
});
