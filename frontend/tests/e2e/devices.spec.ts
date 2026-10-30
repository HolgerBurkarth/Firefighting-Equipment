import { readFile } from 'node:fs/promises';
import type { Locator, Page } from '@playwright/test';
import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { deviceDetail, deviceJson, eventsEndpoint } from './support/devices';
import { horizontalOverflow } from './support/layout';
import { deviceTypeServer, locationServer } from './support/masterData';

/** Kalendertag in Europe/Berlin (wie die App) plus `days`, als JJJJ-MM-TT. */
function berlinDay(days: number): string {
    const today = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' }).format(
        new Date(),
    );
    const date = new Date(`${today}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

const LAGER = { id: 30, name: 'Lager', path: 'Lager' };

const DEVICES = [
    deviceJson(1, { next_inspection_due: berlinDay(100) }),
    deviceJson(2, {
        status: 'maintenance',
        status_label: 'in Wartung',
        next_inspection_due: berlinDay(10),
    }),
    deviceJson(3, {
        identifier: 'PA 4',
        device_type: { id: 2, name: 'Atemschutzgerät' },
        location: LAGER,
        status: 'defective',
        status_label: 'defekt',
        next_inspection_due: berlinDay(-3),
    }),
    deviceJson(4, {
        identifier: 'HRT 2',
        device_type: { id: 3, name: 'Funkgerät' },
        location: { id: 20, name: 'LF 10', path: 'LF 10' },
    }),
    deviceJson(5, {
        identifier: 'M9',
        location: LAGER,
        status: 'retired',
        status_label: 'ausgesondert',
        next_inspection_due: berlinDay(-400),
    }),
];

/** Fächer → übergeordneter Standort (für include_sublocations). */
const PARENTS: Readonly<Record<number, number>> = { 11: 10, 12: 10 };

/**
 * GET devices wie DeviceListHandler, so weit die Tests es brauchen: Status (ohne Angabe ohne
 * ausgesonderte), include_retired, Typ, Standort mit Fächern, `q` in der Kennung, Seiten. Die
 * Sortierung rechnet der Server; hier nur „desc“ = umgekehrte Reihenfolge.
 */
function deviceServer(all: readonly Record<string, unknown>[]) {
    return (request: MockRequest): MockResponse => {
        const params = new URL(request.url).searchParams;
        const statuses = params.get('status')?.split(',') ?? null;
        const typeId = params.get('device_type_id');
        const locationId = params.get('location_id');
        const sub = params.get('include_sublocations') === '1';
        const q = params.get('q')?.toLowerCase() ?? null;
        let hits = all.filter((device) => {
            const status = String(device['status']);
            const location = (device['location'] as { id: number }).id;
            const type = (device['device_type'] as { id: number }).id;
            return (
                (statuses === null
                    ? status !== 'retired' || params.get('include_retired') === '1'
                    : statuses.includes(status)) &&
                (typeId === null || type === Number(typeId)) &&
                (locationId === null ||
                    location === Number(locationId) ||
                    (sub && PARENTS[location] === Number(locationId))) &&
                (q === null || String(device['identifier']).toLowerCase().includes(q))
            );
        });
        if (params.get('dir') === 'desc') {
            hits = [...hits].reverse();
        }
        const limit = Number(params.get('limit'));
        const offset = Number(params.get('offset'));
        return {
            status: 200,
            body: {
                devices: hits.slice(offset, offset + limit),
                total: hits.length,
                limit,
                offset,
            },
        };
    };
}

const MASTER_DATA = {
    ...deviceTypeServer([
        { id: 1, name: 'Atemschutzmaske', count: 3 },
        { id: 2, name: 'Atemschutzgerät', count: 1 },
        { id: 3, name: 'Funkgerät', count: 1 },
    ]),
    ...locationServer([
        {
            id: 10,
            name: 'HLF 20',
            children: [
                { id: 11, name: 'G1', count: 2 },
                { id: 12, name: 'G2' },
            ],
        },
        { id: 20, name: 'LF 10', own: 1 },
        { id: 30, name: 'Lager', kind: 'room', own: 1 },
    ]).api,
};

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...MASTER_DATA,
        'GET api/v1/devices': deviceServer(DEVICES),
    },
});

function identifiers(page: Page): Locator {
    return page.getByTestId('device-table').locator('.device-link');
}

/** Letzte Abfrage der Liste (GET devices mit Seitengröße 50), als Query-Parameter. */
function lastListQuery(requests: readonly MockRequest[]): URLSearchParams {
    const list = requests.filter(
        (request) =>
            request.path === 'api/v1/devices' &&
            new URL(request.url).searchParams.get('limit') === '50',
    );
    return new URL(list.at(-1)?.url ?? 'http://x/').searchParams;
}

async function open(page: Page, query = ''): Promise<void> {
    await page.goto(`/firefighting/test/geraete${query}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Geräte' })).toBeVisible();
}

async function choose(page: Page, label: string, option: string): Promise<void> {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test.describe('Geräteliste (PC)', () => {
    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Spalten, Prüfdatum farbig, ausgesonderte ausgeblendet', async ({ page, apiRequests }) => {
        await open(page);
        await expect(identifiers(page)).toHaveText(['M1', 'M2', 'PA 4', 'HRT 2']);
        await expect(page.getByTestId('device-count')).toHaveText('4 Geräte');
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Geräte', exact: true }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId('device-row-3')).toContainText('Atemschutzgerät');
        await expect(page.getByTestId('device-row-3')).toContainText('Lager');
        await expect(page.getByTestId('device-row-3')).toContainText('defekt');
        await expect(page.getByTestId('device-row-3').locator('.cell-due')).toContainText(
            'überfällig',
        );
        await expect(page.getByTestId('device-row-2').locator('.cell-due')).toContainText(
            'bald fällig',
        );
        await expect(page.getByTestId('device-row-4').locator('.cell-due')).toHaveText('–', {
            useInnerText: true,
        });
        await expect(page.getByRole('columnheader', { name: 'Kennung' })).toHaveAttribute(
            'aria-sort',
            'ascending',
        );
        expect(Object.fromEntries(lastListQuery(apiRequests))).toEqual({
            limit: '50',
            offset: '0',
            sort: 'identifier',
            dir: 'asc',
        });
        // Nur am Handy: Auswahl der Sortierung.
        await expect(page.getByRole('combobox', { name: 'Sortieren nach' })).toBeHidden();
    });

    test('Filter stehen in der Adresse und gehen an den Server', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'defekt', exact: true }).click();
        await expect(page).toHaveURL(/\/geraete\?status=defective$/);
        await expect(identifiers(page)).toHaveText(['PA 4']);
        await expect(page.getByRole('button', { name: 'defekt', exact: true })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.getByTestId('device-count')).toHaveText('1 Gerät gefunden');
        expect(lastListQuery(apiRequests).get('status')).toBe('defective');

        // Mit Statusfilter kommen ausgesonderte als weiterer Status dazu.
        await page.getByLabel('Ausgesonderte anzeigen').check();
        await expect(identifiers(page)).toHaveText(['PA 4', 'M9']);
        expect(lastListQuery(apiRequests).get('status')).toBe('defective,retired');
        await page.getByRole('button', { name: 'Filter zurücksetzen' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
        await expect(identifiers(page)).toHaveText(['M1', 'M2', 'PA 4', 'HRT 2']);

        await choose(page, 'Standort', 'HLF 20');
        await expect(page).toHaveURL(/\/geraete\?standort=10$/);
        await expect(identifiers(page)).toHaveText(['M1', 'M2']);
        expect(lastListQuery(apiRequests).get('include_sublocations')).toBe('1');

        await choose(page, 'Gerätetyp', 'Atemschutzmaske');
        await expect(page).toHaveURL(/typ=1/);
        await page.getByLabel('Ausgesonderte anzeigen').check();
        expect(lastListQuery(apiRequests).get('include_retired')).toBe('1');

        // Nach dem Neuladen gelten dieselben Filter.
        await page.reload();
        await expect(page.getByRole('combobox', { name: 'Standort' })).toHaveText(/HLF 20/);
        await expect(page.getByRole('combobox', { name: 'Gerätetyp' })).toHaveText(
            /Atemschutzmaske/,
        );
        await expect(page.getByLabel('Ausgesonderte anzeigen')).toBeChecked();
        await expect(identifiers(page)).toHaveText(['M1', 'M2']);

        await choose(page, 'Standort', 'Lager');
        await expect(identifiers(page)).toHaveText(['M9']);
        await expect(page.getByTestId('device-row-5')).toContainText('ausgesondert');
        await expect(page.getByTestId('device-row-5').locator('.cell-due')).not.toContainText(
            'überfällig',
        );
    });

    test('Suche erst nach dem Tippen, Spaltenköpfe sortieren', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('searchbox', { name: 'Geräte filtern' }).fill('pa');
        await expect(page).toHaveURL(/suche=pa/);
        await expect(identifiers(page)).toHaveText(['PA 4']);
        expect(lastListQuery(apiRequests).get('q')).toBe('pa');
        // Eine Abfrage für die ganze Eingabe, nicht je Buchstabe.
        expect(apiRequests.filter((r) => new URL(r.url).searchParams.has('q'))).toHaveLength(1);

        await page.getByRole('searchbox', { name: 'Geräte filtern' }).fill('');
        await expect(page).toHaveURL(/\/geraete$/);
        const status = page.getByRole('button', { name: 'Status', exact: true });
        await status.click();
        await expect(page).toHaveURL(/sortierung=status$/);
        expect(lastListQuery(apiRequests).get('sort')).toBe('status');
        await status.click();
        await expect(page).toHaveURL(/sortierung=status&richtung=desc$/);
        await expect(page.getByRole('columnheader', { name: 'Status' })).toHaveAttribute(
            'aria-sort',
            'descending',
        );
        await expect(identifiers(page)).toHaveText(['HRT 2', 'PA 4', 'M2', 'M1']);
        await expect(page.getByRole('columnheader', { name: 'Kennung' })).toHaveAttribute(
            'aria-sort',
            'none',
        );
    });

    test.describe('Export', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
                ...ADMIN_OVERVIEW,
                ...MASTER_DATA,
                'GET api/v1/devices': deviceServer(DEVICES),
                'GET api/v1/devices/export': {
                    status: 200,
                    contentType: 'text/csv; charset=utf-8',
                    headers: {
                        'Content-Disposition': 'attachment; filename="geraete-test-2026-10-30.csv"',
                    },
                    text: '﻿Kennung;Gerätetyp\r\nPA 4;Atemschutzgerät\r\n',
                },
            },
        });

        test('lädt die gefilterte und sortierte Liste als CSV', async ({ page, apiRequests }) => {
            await open(page, '?status=defective&sortierung=location&richtung=desc');
            await expect(identifiers(page)).toHaveText(['PA 4']);
            const download = page.waitForEvent('download');
            await page.getByRole('button', { name: 'Export (CSV)' }).click();
            const file = await download;
            expect(file.suggestedFilename()).toBe('geraete-test-2026-10-30.csv');
            expect(await readFile(await file.path(), 'utf8')).toBe(
                '﻿Kennung;Gerätetyp\r\nPA 4;Atemschutzgerät\r\n',
            );
            const exported = apiRequests.find((r) => r.path === 'api/v1/devices/export');
            expect(Object.fromEntries(new URL(exported?.url ?? 'http://x/').searchParams)).toEqual({
                status: 'defective',
                sort: 'location',
                dir: 'desc',
            });
        });
    });

    test.describe('Export gestört', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
                ...ADMIN_OVERVIEW,
                ...MASTER_DATA,
                'GET api/v1/devices': deviceServer(DEVICES),
                'GET api/v1/devices/export': DATABASE_UNAVAILABLE,
            },
        });

        test('Meldung statt Datei', async ({ page }) => {
            await open(page);
            await page.getByRole('button', { name: 'Export (CSV)' }).click();
            await expect(
                page.getByText('Der Server ist gerade nicht erreichbar.', { exact: false }),
            ).toBeVisible();
            await expect(page.getByRole('button', { name: 'Export (CSV)' })).toBeEnabled();
        });
    });

    test.describe('viele Geräte', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
                ...ADMIN_OVERVIEW,
                ...MASTER_DATA,
                'GET api/v1/devices': deviceServer(
                    Array.from({ length: 120 }, (_, i) => deviceJson(i + 1)),
                ),
            },
        });

        test('„Weitere laden“ holt die nächsten 50', async ({ page, apiRequests }) => {
            await open(page);
            await expect(identifiers(page)).toHaveCount(50);
            await expect(page.getByText('50 von 120 angezeigt')).toBeVisible();
            await page.getByRole('button', { name: 'Weitere laden' }).click();
            await expect(identifiers(page)).toHaveCount(100);
            await page.getByRole('button', { name: 'Weitere laden' }).click();
            await expect(identifiers(page)).toHaveCount(120);
            await expect(page.getByRole('button', { name: 'Weitere laden' })).toHaveCount(0);
            const offsets = apiRequests
                .filter((r) => r.path === 'api/v1/devices')
                .map((r) => new URL(r.url).searchParams)
                .filter((params) => params.get('limit') === '50')
                .map((params) => params.get('offset'));
            expect(offsets).toEqual(['0', '50', '100']);
        });
    });

    test('Kacheln und Namen führen zur gefilterten Liste', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await page.getByTestId('tile-not-ready').click();
        await expect(page).toHaveURL(/\/geraete\?status=maintenance(,|%2C)defective$/);
        await expect(identifiers(page)).toHaveText(['M2', 'PA 4']);

        await page.goto('/firefighting/test/standorte');
        await page.getByRole('link', { name: 'HLF 20', exact: true }).click();
        await expect(page).toHaveURL(/\/geraete\?standort=10$/);
        await expect(page.getByRole('combobox', { name: 'Standort' })).toHaveText(/HLF 20/);

        await page.goto('/firefighting/test/geraetetypen');
        await page.getByRole('link', { name: 'Funkgerät', exact: true }).click();
        await expect(page).toHaveURL(/\/geraete\?typ=3$/);
        await expect(identifiers(page)).toHaveText(['HRT 2']);
    });
});

test('Handy: Karten, Sortierung über Auswahl und Richtung', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page);
    await expect(identifiers(page)).toHaveText(['M1', 'M2', 'PA 4', 'HRT 2']);
    await expect(page.getByRole('columnheader', { name: 'Kennung' })).toBeHidden();
    await expect(page.getByTestId('device-row-3')).toContainText('Prüfung:');
    await choose(page, 'Sortieren nach', 'Standort');
    await expect(page).toHaveURL(/sortierung=location$/);
    await page.getByRole('button', { name: 'Absteigend sortieren' }).click();
    await expect(page).toHaveURL(/sortierung=location&richtung=desc$/);
    await expect(page.getByRole('button', { name: 'Aufsteigend sortieren' })).toBeVisible();
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    const link = await identifiers(page).first().boundingBox();
    expect(Math.round(link?.height ?? 0)).toBeGreaterThanOrEqual(44);
});

/** Für die Buchung aus der Liste (übernommen, Version 2). */
function applied(request: MockRequest): MockResponse {
    const body = request.body as Record<string, unknown>;
    return {
        status: 200,
        body: {
            device: deviceJson(1, {
                version: 2,
                status: body['status'],
                status_label: 'in Wartung',
            }),
            result: {
                outcome: 'applied',
                outcome_label: 'übernommen',
                reason: null,
                reason_label: null,
                message: 'Übernommen.',
                stale_confirmed: false,
                client_ref: body['client_ref'],
                replayed: false,
                notice_id: null,
            },
        },
    };
}

test.describe('Buchung aus der Liste', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
            ...ADMIN_OVERVIEW,
            ...MASTER_DATA,
            'GET api/v1/devices': deviceServer(DEVICES),
            ...deviceDetail(DEVICES[0] ?? deviceJson(1)),
            'GET api/v1/devices/1/events': eventsEndpoint([]),
            'POST api/v1/devices/1/state': applied,
        },
    });

    test('Kennung öffnet das Gerätedetail; Zurück führt zur gefilterten Liste', async ({
        page,
    }) => {
        await open(page, '?status=ready');
        await identifiers(page).first().click();
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
        await page.getByRole('link', { name: 'Umbuchen / Status ändern' }).click();
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
        await expect(page.getByTestId('booking-notice')).toContainText('M1');
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/geraete\?status=ready$/);
    });
});
