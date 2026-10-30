import type { Page } from '@playwright/test';
import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import {
    deviceJson,
    devicesEndpoint,
    eventJson,
    eventsEndpoint,
    fullDevice,
    LOCATIONS,
} from './support/devices';

/** 29.10.2026 14:30 in Berlin (Winterzeit; Zeitzone in playwright.config.ts). */
const NOW = new Date('2026-10-29T13:30:00Z');

/** M1 mit allen Stammdaten, Prüfdatum überfällig. */
const M1 = deviceJson(1, {
    scan_code: '010400638133393110L42\u001d21ABC',
    serial_number: 'SN-4711',
    manufacturer: 'Dräger',
    model: 'FPS 7000',
    next_inspection_due: '2026-10-01',
});
const M1_FULL = fullDevice(M1, { procured_on: '2021-04-15', note: 'Zeile 1\nZeile 2' });

/** Neueste zuerst, wie GET devices/{id}/events. */
const EVENTS = [
    eventJson(5, {
        type_label: 'Umgebucht und Status geändert',
        status: { from: 'ready', from_label: 'einsatzbereit', to: 'defective', to_label: 'defekt' },
        comment: 'Ausatemventil undicht',
    }),
    eventJson(4, {
        type_label: 'Umbuchung nicht übernommen',
        occurred_at: '2026-10-28T17:30:00Z',
        received_at: '2026-10-29T07:15:00Z',
        created_at: '2026-10-29T07:15:00Z',
        applied: false,
        not_applied_reason: 'older',
        not_applied_label: 'Neuerer Stand vorhanden',
        user: { id: 8, name: 'Ben' },
        location: { from: null, to: { id: 30, name: 'Lager', path: 'Lager' } },
    }),
    eventJson(3, {
        type: 'due_changed',
        type_label: 'Prüfdatum geändert',
        occurred_at: '2026-10-12T08:00:00Z',
        location: null,
        inspection_due: { from: null, to: '2026-10-01' },
    }),
    eventJson(2, {
        type: 'field_changed',
        type_label: 'Stammdaten geändert',
        occurred_at: '2026-10-12T07:59:00Z',
        location: null,
        field: {
            name: 'scan_code',
            label: 'Scan-Code',
            old: null,
            new: '010400638133393110L42\u001d21ABC',
        },
    }),
    eventJson(1, {
        type: 'created',
        type_label: 'Angelegt',
        occurred_at: '2026-01-05T09:00:00Z',
        location: { from: null, to: { id: 11, name: 'G1', path: 'HLF 20 / G1' } },
        status: { from: null, from_label: null, to: 'ready', to_label: 'einsatzbereit' },
        user: { id: 3, name: 'Gelöschter Benutzer #3' },
    }),
];

function common(events: readonly Record<string, unknown>[]) {
    return {
        'GET api/v1/auth/me': { status: 200, body: meBody('user') },
        'GET api/v1/devices': devicesEndpoint([M1]),
        'GET api/v1/devices/1': { status: 200, body: { device: M1_FULL } },
        'GET api/v1/devices/1/events': eventsEndpoint(events),
        ...LOCATIONS,
    } as const;
}

async function openDevice(page: Page): Promise<void> {
    await page.goto('/firefighting/test/suchen');
    await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('M1');
    await page.getByTestId('device-card').first().click();
    await expect(
        page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
    ).toBeVisible();
}

async function openDetails(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Details und Verlauf' }).click();
    await expect(page.getByText('Details und Verlauf', { exact: true })).toBeVisible();
}

function eventRequests(requests: readonly MockRequest[]): (string | null)[] {
    return requests
        .filter((r) => r.path === 'api/v1/devices/1/events')
        .map((r) => new URL(r.url).searchParams.get('offset'));
}

test.describe('Details und Verlauf', () => {
    test.use({ api: common(EVENTS) });

    test.beforeEach(async ({ page }) => {
        await page.clock.setFixedTime(NOW);
    });

    test('Stammdaten und Verlauf; Zurück führt zum Umbuchen', async ({ page, apiRequests }) => {
        await openDevice(page);
        await openDetails(page);
        await expect(page).toHaveURL(/\/geraete\/1\?details=1$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
        ).toBeFocused();
        await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);

        const state = page.getByTestId('details-state');
        await expect(state).toContainText('HLF 20 / G1');
        await expect(state).toContainText('✓ einsatzbereit');
        await expect(state).toContainText('Nächste Prüfung: 01.10.2026 · überfällig');

        await expect(page.getByTestId('master-data').locator('.master-row')).toHaveText([
            /Kennung\s*M1/,
            /Scan-Code\s*010400638133393110L42\[GS\]21ABC/,
            /Gerätetyp\s*Atemschutzmaske/,
            /Seriennummer\s*SN-4711/,
            /Hersteller\s*Dräger/,
            /Modell\s*FPS 7000/,
            /Beschaffungsdatum\s*15\.04\.2021/,
            /Notiz\s*Zeile 1\s*Zeile 2/,
        ]);

        await expect(page.getByRole('heading', { name: 'Verlauf' })).toContainText('5 Einträge');
        const entries = page.getByTestId('history-entry');
        await expect(entries).toHaveCount(5);
        await expect(entries.nth(0)).toContainText('Umgebucht und Status geändert');
        await expect(entries.nth(0)).toContainText('heute 13:00');
        await expect(entries.nth(0)).toContainText('von Anna Muster');
        await expect(entries.nth(0)).toContainText(/Standort:\s*HLF 20 \/ G1 → LF 10/);
        await expect(entries.nth(0)).toContainText(/Status:\s*einsatzbereit → defekt/);
        await expect(entries.nth(0)).toContainText('„Ausatemventil undicht“');
        await expect(entries.nth(0)).not.toContainText('Eingang beim Server');
        // Offline-Buchung, nicht übernommen: Gewünschtes, Grund, später Eingang.
        await expect(entries.nth(1)).toContainText('Umbuchung nicht übernommen');
        await expect(entries.nth(1)).toContainText('gestern 18:30');
        await expect(entries.nth(1)).toContainText(/Standort \(gewünscht\):\s*Lager/);
        await expect(entries.nth(1)).toContainText(/Grund:\s*Neuerer Stand vorhanden/);
        await expect(entries.nth(1)).toContainText(/Eingang beim Server:\s*heute 08:15/);
        await expect(entries.nth(1)).toHaveClass(/history-not-applied/);
        await expect(entries.nth(2)).toContainText('12.10.2026, 10:00');
        await expect(entries.nth(2)).toContainText(/Prüfdatum:\s*keins → 01\.10\.2026/);
        await expect(entries.nth(3)).toContainText(
            /Scan-Code:\s*leer → 010400638133393110L42\[GS\]21ABC/,
        );
        await expect(entries.nth(4)).toContainText('Angelegt');
        await expect(entries.nth(4)).toContainText('von Gelöschter Benutzer #3');
        await expect(entries.nth(4)).toContainText(/Standort:\s*HLF 20 \/ G1/);
        await expect(entries.nth(4)).toContainText(/Status:\s*einsatzbereit/);
        await expect(page.getByTestId('history-more')).toHaveCount(0);
        expect(eventRequests(apiRequests)).toEqual(['0']);

        await page.getByRole('button', { name: 'Zurück zum Umbuchen' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByRole('button', { name: 'Details und Verlauf' })).toBeFocused();
        // Zurück führt danach zu „Suchen“ (die Details stehen nicht dazwischen).
        await page.getByRole('button', { name: 'Zurück', exact: true }).click();
        await expect(page).toHaveURL(/\/suchen$/);
    });

    test('Eingaben beim Umbuchen bleiben; Zurück-Knopf des Browsers schließt nur die Details', async ({
        page,
    }) => {
        await openDevice(page);
        await page.getByRole('button', { name: 'defekt', exact: true }).click();
        await page.getByLabel('Was ist defekt?').fill('Ventil klemmt');
        await openDetails(page);
        await page.goBack();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByRole('button', { name: 'defekt', exact: true })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.getByLabel('Was ist defekt?')).toHaveValue('Ventil klemmt');
        await page.goForward();
        await expect(page.getByTestId('history-entry')).toHaveCount(5);
    });

    test('direkt mit ?details=1 geöffnet: Zurück bleibt beim Gerät', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/1?details=1');
        await expect(page.getByTestId('history-entry')).toHaveCount(5);
        await page.getByRole('button', { name: 'Zurück zum Umbuchen' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeVisible();
    });

    test('Handy-Breite: kein seitliches Überstehen, Link mindestens 44 px', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto('/firefighting/test/geraete/1');
        const link = page.getByRole('button', { name: 'Details und Verlauf' });
        const box = await link.boundingBox();
        expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
        await link.click();
        await expect(page.getByTestId('history-entry')).toHaveCount(5);
        // Wie in booking.spec.ts: nicht über clientWidth (Scrollleiste des emulierten iPhones).
        const overflow = await page.evaluate(() => {
            const width = document.documentElement.getBoundingClientRect().right;
            return [...document.querySelectorAll('body *')]
                .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                .map((element) => `${element.tagName}.${element.className}`);
        });
        expect(overflow).toEqual([]);
    });
});

test.describe('Langer Verlauf', () => {
    const many = Array.from({ length: 25 }, (_, i) =>
        eventJson(100 - i, { comment: `Eintrag ${String(i + 1)}` }),
    );
    test.use({ api: common(many) });

    test('lädt 20 Einträge, dann den Rest', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/geraete/1?details=1');
        await expect(page.getByTestId('history-entry')).toHaveCount(20);
        await expect(page.getByRole('heading', { name: 'Verlauf' })).toContainText('25 Einträge');
        await page.getByTestId('history-more').click();
        await expect(page.getByTestId('history-entry')).toHaveCount(25);
        await expect(page.getByTestId('history-entry').last()).toContainText('„Eintrag 25“');
        await expect(page.getByTestId('history-more')).toHaveCount(0);
        expect(eventRequests(apiRequests)).toEqual(['0', '20']);
        const limits = apiRequests
            .filter((r) => r.path === 'api/v1/devices/1/events')
            .map((r) => new URL(r.url).searchParams.get('limit'));
        expect(limits).toEqual(['20', '20']);
    });
});

test.describe('Verlauf nicht ladbar', () => {
    let calls = 0;
    test.use({
        api: {
            ...common(EVENTS),
            'GET api/v1/devices/1/events': (request): MockResponse => {
                calls += 1;
                return calls === 1 ? DATABASE_UNAVAILABLE : eventsEndpoint(EVENTS)(request);
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('Fehler mit erneutem Versuch, Stammdaten bleiben sichtbar', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/1?details=1');
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        await expect(page.getByTestId('master-data')).toContainText('SN-4711');
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(page.getByTestId('history-entry')).toHaveCount(5);
    });
});

test.describe('Ausgesondertes Gerät', () => {
    const retired = deviceJson(1, { status: 'retired', status_label: 'ausgesondert' });
    test.use({
        api: {
            ...common(EVENTS),
            'GET api/v1/devices/1': { status: 200, body: { device: fullDevice(retired) } },
        },
    });

    test('Details und Verlauf bleiben erreichbar', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/1');
        await expect(page.getByTestId('device-retired')).toBeVisible();
        await openDetails(page);
        await expect(page.getByTestId('details-state')).toContainText('– ausgesondert');
        await expect(page.getByTestId('history-entry')).toHaveCount(5);
    });
});
