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
import { adminOverview, noticeJson, summaryJson } from './support/admin';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';
import { horizontalOverflow } from './support/layout';
import { delegate, deviceTypeServer, locationServer } from './support/masterData';

/**
 * Protokoll (`protokoll`, KONZEPT.md, "Hinweise für Admins"): Hinweise zu Offline-Buchungen mit
 * Filter Offen/Erledigt/Alle, ein Hinweis mit beiden Ständen, Rückfrage per Mail, Erledigen mit
 * Notiz und Wieder öffnen, Umbuchen und zurück; Reiter „Verlauf exportieren“. Der simulierte
 * Server merkt sich jede Änderung.
 */

const ME = { status: 200, body: meBody('admin', 'Thomas Keller') } as const;
const M1 = deviceJson(1, {
    location: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
    status: 'defective',
    status_label: 'defekt',
});

function initialNotices(): Record<string, unknown>[] {
    return [
        noticeJson(3),
        noticeJson(2, {
            kind: 'not_applied_location_locked',
            kind_label: 'Nicht übernommen (Standort gesperrt)',
            stale_confirmed: true,
            device: { id: 2, identifier: 'PA 4', device_type: 'Atemschutzgerät' },
            booking: {
                ...(noticeJson(2)['booking'] as Record<string, unknown>),
                location: { id: 40, name: 'Keller', path: 'Keller' },
                status: null,
                status_label: null,
                comment: null,
            },
            other: {
                user: null,
                occurred_at: '2026-10-29T06:00:00Z',
                location: { id: 30, name: 'Lager', path: 'Lager' },
                status: 'ready',
                status_label: 'einsatzbereit',
            },
        }),
        noticeJson(1, {
            kind: 'history_only',
            kind_label: 'Nur Historie (neuerer Stand vorhanden)',
            status: 'resolved',
            status_label: 'erledigt',
            history: [
                {
                    id: 5,
                    action: 'resolve',
                    action_label: 'Erledigt',
                    user: { id: 7, name: 'Thomas Keller', email: 'anna@example.org' },
                    at: '2026-10-30T10:00:00Z',
                    note: null,
                },
            ],
        }),
    ];
}

type Respond = (request: MockRequest) => MockResponse | null;

/** GET notices (Filter, Seiten), GET notices/{id}, erledigen und wieder öffnen wie NoticeActionHandler. */
function noticeServer(
    notices: Record<string, unknown>[] = initialNotices(),
    respond: Respond = () => null,
) {
    let nextAction = 100;
    const find = (id: number) => notices.find((notice) => notice['id'] === id);
    const ok = (notice: Record<string, unknown> | undefined): MockResponse =>
        notice === undefined
            ? { status: 404, body: { error: 'not_found', message: 'Nicht gefunden.' } }
            : { status: 200, body: { notice } };
    const change =
        (id: number, status: 'open' | 'resolved') =>
        (request: MockRequest): MockResponse => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const notice = find(id);
            if (notice !== undefined && notice['status'] !== status) {
                const note = (request.body as Record<string, unknown>)['note'];
                notice['status'] = status;
                notice['status_label'] = status === 'open' ? 'offen' : 'erledigt';
                notice['history'] = [
                    ...(notice['history'] as unknown[]),
                    {
                        id: nextAction++,
                        action: status === 'open' ? 'reopen' : 'resolve',
                        action_label: status === 'open' ? 'Wieder geöffnet' : 'Erledigt',
                        user: { id: 7, name: 'Thomas Keller', email: 'anna@example.org' },
                        at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
                        note: typeof note === 'string' ? note : null,
                    },
                ];
            }
            return ok(notice);
        };
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/notices': (request) => {
            const params = new URL(request.url).searchParams;
            const status = params.get('status') ?? 'open';
            const limit = Number(params.get('limit') ?? '50');
            const offset = Number(params.get('offset') ?? '0');
            const matching = notices.filter(
                (notice) => status === 'all' || notice['status'] === status,
            );
            return {
                status: 200,
                body: {
                    notices: matching.slice(offset, offset + limit),
                    total: matching.length,
                    open: notices.filter((notice) => notice['status'] === 'open').length,
                    limit,
                    offset,
                },
            };
        },
        'GET api/v1/notices/999': () => ok(undefined),
    };
    for (const notice of notices) {
        const id = Number(notice['id']);
        api[`GET api/v1/notices/${String(id)}`] = () => ok(find(id));
        api[`POST api/v1/notices/${String(id)}/resolve`] = change(id, 'resolved');
        api[`POST api/v1/notices/${String(id)}/reopen`] = change(id, 'open');
    }
    return api;
}

let server = noticeServer();

function useServer(create: () => ReturnType<typeof noticeServer> = () => noticeServer()): void {
    test.beforeEach(() => {
        server = create();
    });
}

const OVERVIEW = adminOverview({
    summary: summaryJson({ ready: 3, defective: 1, openNotices: 2 }),
});

function sent(requests: readonly MockRequest[], method: string, path: string): unknown[] {
    return requests.filter((r) => r.method === method && r.path === path).map((r) => r.body);
}

async function open(page: Page, query = ''): Promise<void> {
    await page.goto(`/firefighting/test/protokoll${query}`);
    await expect(page.getByRole('heading', { level: 1, name: 'Protokoll' })).toBeVisible();
}

test.describe('Protokoll: Hinweise', () => {
    useServer();
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...OVERVIEW,
            ...delegate(() => server),
            ...deviceDetail(M1),
            ...deviceDetail(
                deviceJson(2, {
                    identifier: 'PA 4',
                    location: { id: 30, name: 'Lager', path: 'Lager' },
                }),
            ),
        },
    });

    test('offene Hinweise, Filter in der Adresse, Menü und Reiter mit Zähler', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link', { name: /Protokoll/ })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await expect(nav.getByRole('link', { name: /Protokoll/ })).toContainText('2');
        await expect(page.getByTestId('open-count')).toHaveText('2');
        await expect(page.getByTestId('notice-count')).toHaveText('2 offene Hinweise');
        const list = page.getByTestId('notice-list');
        await expect(list.locator('.notice-kind')).toHaveText([
            'Anderen Stand überschrieben',
            'Nicht übernommen (Standort gesperrt)',
        ]);
        await expect(page.getByTestId('notice-3')).toContainText('M1 · Atemschutzmaske');
        await expect(page.getByTestId('notice-3')).toContainText(
            'Buchung: HLF 20 / G1 · defekt · von Gerätewart',
        );
        await expect(page.getByTestId('notice-3')).toContainText(
            'Überschriebener Stand: Lager · einsatzbereit',
        );
        await expect(page.getByTestId('notice-2')).toContainText(
            'Buchung: Keller · von Gerätewart',
        );
        await expect(page.getByTestId('notice-2')).toContainText('Stand beim Eingang: Lager');
        await expect(page.getByRole('button', { name: 'Offen (2)' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        await page.getByRole('button', { name: 'Erledigt' }).click();
        await expect(page).toHaveURL(/\/protokoll\?status=erledigt$/);
        await expect(page.getByTestId('notice-count')).toHaveText('1 erledigter Hinweis');
        await expect(list.locator('.notice-kind')).toHaveText([
            'Nur Historie (neuerer Stand vorhanden)',
        ]);
        await page.getByRole('button', { name: 'Alle' }).click();
        await expect(page.getByTestId('notice-count')).toHaveText('3 Hinweise');

        // Neu laden behält den Filter.
        await page.reload();
        await expect(page.getByRole('button', { name: 'Alle' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        await expect(page.getByTestId('notice-count')).toHaveText('3 Hinweise');
    });

    test('Hinweis: Erklärung, Buchung, anderer Stand, Gerät jetzt und Rückfrage per Mail', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await page.getByTestId('notice-3').getByRole('link').click();
        await expect(page).toHaveURL(/\/protokoll\/3$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Anderen Stand überschrieben' }),
        ).toBeVisible();
        await expect(page.getByTestId('notice-status')).toHaveText('offen');
        await expect(page.getByTestId('notice-explanation')).toContainText(
            'hat diesen Stand überschrieben',
        );
        const booking = page.getByTestId('booking');
        await expect(booking.locator('dd').first()).toHaveText(
            /^\s*Gerätewart\s*geraetewart\+1@example\.org\s*$/,
        );
        await expect(booking).toContainText('HLF 20 / G1');
        await expect(booking).toContainText('defekt');
        await expect(booking).toContainText('Ventil klemmt');
        const other = page.getByTestId('other');
        await expect(other.getByRole('heading')).toHaveText('Überschriebener Stand');
        await expect(other.locator('dd').first()).toHaveText(
            /^\s*Kommandant\s*kommandant@example\.org\s*$/,
        );
        await expect(other).toContainText('Lager');
        await expect(page.getByTestId('device-now')).toContainText('HLF 20 / G1');

        const mail = booking.getByRole('link', { name: 'Rückfrage per E-Mail an Gerätewart' });
        const href = (await mail.getAttribute('href')) ?? '';
        expect(href.startsWith('mailto:geraetewart+1@example.org?subject=')).toBe(true);
        const params = new URLSearchParams(href.split('?')[1]);
        expect(params.get('subject')).toBe('Rückfrage zu Ihrer Buchung von Atemschutzmaske M1');
        expect(params.get('body')).toContain('(HLF 20 / G1 · defekt) habe ich eine Rückfrage:');
        await expect(
            other.getByRole('link', { name: 'Rückfrage per E-Mail an Kommandant' }),
        ).toHaveAttribute('href', /^mailto:kommandant@example\.org\?/);
        await expect(page.getByText('Noch nicht bearbeitet', { exact: false })).toBeVisible();

        // Zurück zur Liste.
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/protokoll$/);
    });

    test('ohne anderes Konto keine Rückfrage, „nach Rückfrage gesendet“ in der Erklärung', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/protokoll/2');
        await expect(page.getByTestId('notice-explanation')).toContainText(
            'Außerdem war die Buchung älter als erlaubt',
        );
        await expect(page.getByTestId('other').getByRole('heading')).toHaveText(
            'Stand beim Eingang',
        );
        await expect(page.getByTestId('other').getByRole('link')).toHaveCount(0);
        await expect(page.getByTestId('booking')).toContainText('Gewünschter Standort');
        await expect(page.getByTestId('booking')).not.toContainText('Gewünschter Status');
    });

    test('Erledigen mit Notiz, dann wieder öffnen', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByTestId('notice-3').getByRole('link').click();
        await page.getByRole('button', { name: 'Erledigen …' }).click();
        const dialog = page.getByRole('dialog', { name: 'Hinweis erledigen' });
        await expect(dialog).toContainText('Anderen Stand überschrieben · M1');
        const note = dialog.getByLabel('Notiz (freiwillig)');
        await note.fill('x'.repeat(501));
        await expect(dialog.getByText('Höchstens 500 Zeichen (jetzt 501).')).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Erledigen' })).toBeDisabled();
        await note.fill(' Mit Gerätewart geklärt\n\tMaske getauscht ');
        await dialog.getByRole('button', { name: 'Erledigen' }).click();

        await expect(page.getByTestId('success-notice')).toHaveText('Der Hinweis ist erledigt.');
        expect(sent(apiRequests, 'POST', 'api/v1/notices/3/resolve')).toEqual([
            { note: 'Mit Gerätewart geklärt\n Maske getauscht' },
        ]);
        await expect(page.getByTestId('notice-status')).toHaveText('erledigt');
        const history = page.getByTestId('notice-history');
        await expect(history).toContainText('Erledigt');
        await expect(history).toContainText('von Thomas Keller');
        await expect(history.locator('.history-note')).toHaveText(
            'Mit Gerätewart geklärt\n Maske getauscht',
        );
        // Zähler im Menü und die Liste laden neu.
        await expect
            .poll(() => sent(apiRequests, 'GET', 'api/v1/device-summary').length)
            .toBeGreaterThanOrEqual(2);

        await page.getByRole('button', { name: 'Wieder öffnen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Der Hinweis ist wieder offen.',
        );
        await expect(page.getByTestId('notice-status')).toHaveText('offen');
        await expect(history.locator('li')).toHaveCount(2);
        expect(sent(apiRequests, 'POST', 'api/v1/notices/3/reopen')).toEqual([{}]);

        // Ohne Notiz: leeres Objekt.
        await page.getByRole('button', { name: 'Erledigen …' }).click();
        await dialog.getByRole('button', { name: 'Erledigen' }).click();
        await expect(page.getByTestId('notice-status')).toHaveText('erledigt');
        expect(sent(apiRequests, 'POST', 'api/v1/notices/3/resolve').at(-1)).toEqual({});

        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page.getByTestId('notice-count')).toHaveText('1 offener Hinweis');
        await expect(page.getByTestId('notice-3')).toHaveCount(0);
    });

    test('Handy: Liste und Hinweis ohne Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        await expect(page.getByTestId('notice-3')).toBeVisible();
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        await page.getByTestId('notice-3').getByRole('link').click();
        await expect(page.getByTestId('device-now')).toContainText('HLF 20 / G1');
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
});

test.describe('Protokoll: viele Hinweise', () => {
    useServer(() => noticeServer(Array.from({ length: 60 }, (_, i) => noticeJson(60 - i))));
    test.use({
        api: { 'GET api/v1/auth/me': ME, ...OVERVIEW, ...delegate(() => server) },
    });

    test('„Weitere laden“ hängt die nächsten an', async ({ page, apiRequests }) => {
        await open(page);
        await expect(page.getByTestId('notice-list').locator('li')).toHaveCount(50);
        await expect(page.getByText('50 von 60 angezeigt')).toBeVisible();
        await page.getByRole('button', { name: 'Weitere laden' }).click();
        await expect(page.getByTestId('notice-list').locator('li')).toHaveCount(60);
        await expect(page.getByRole('button', { name: 'Weitere laden' })).toHaveCount(0);
        const offsets = apiRequests
            .filter((r) => r.path === 'api/v1/notices')
            .map((r) => new URL(r.url).searchParams.get('offset'));
        expect(offsets).toEqual(['0', '50']);
    });
});

test.describe('Protokoll: Umbuchen vom Hinweis', () => {
    useServer();
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...OVERVIEW,
            ...delegate(() => server),
            ...deviceDetail(M1),
            ...LOCATIONS,
            'GET api/v1/devices': devicesEndpoint([M1]),
            'POST api/v1/devices/1/state': (request) => ({
                status: 200,
                body: {
                    device: deviceJson(1, {
                        version: 2,
                        status: 'ready',
                        status_label: 'einsatzbereit',
                    }),
                    result: {
                        outcome: 'applied',
                        outcome_label: 'übernommen',
                        reason: null,
                        reason_label: null,
                        message: 'Übernommen.',
                        stale_confirmed: false,
                        client_ref: (request.body as Record<string, unknown>)['client_ref'],
                        replayed: false,
                        notice_id: null,
                    },
                },
            }),
        },
    });

    test('Status korrigieren, danach zurück zum Hinweis mit Bestätigung', async ({ page }) => {
        await page.goto('/firefighting/test/protokoll/3');
        await page
            .getByTestId('device-now')
            .getByRole('link', { name: 'Umbuchen / Status ändern' })
            .click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await page.getByRole('button', { name: 'einsatzbereit', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/protokoll\/3$/);
        await expect(page.getByTestId('booking-notice')).toContainText('M1');
        await expect(page.getByTestId('booking-notice')).toContainText('einsatzbereit');
    });
});

test.describe('Protokoll: unbekannter Hinweis', () => {
    useServer();
    test.use({
        allowedConsoleError: HTTP_ERROR_CONSOLE,
        api: { 'GET api/v1/auth/me': ME, ...OVERVIEW, ...delegate(() => server) },
    });

    test('Mail-Link auf einen Hinweis, den es nicht gibt', async ({ page }) => {
        await page.goto('/firefighting/test/protokoll/999');
        await expect(page.getByText('Diesen Hinweis gibt es nicht.')).toBeVisible();
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/protokoll$/);
    });
});

/** Kalendertag in der Ortszeit (wie die App), `days` Tage von heute. */
function localDay(days = 0): string {
    const date = new Date();
    date.setDate(date.getDate() + days);
    const pad = (n: number): string => String(n).padStart(2, '0');
    return `${String(date.getFullYear())}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

const MASTER_DATA = {
    ...deviceTypeServer([
        { id: 1, name: 'Atemschutzmaske', count: 3 },
        { id: 2, name: 'Funkgerät', count: 1 },
    ]),
    ...locationServer([
        { id: 10, name: 'HLF 20', children: [{ id: 11, name: 'G1', count: 2 }] },
        { id: 30, name: 'Lager', kind: 'room', own: 1 },
    ]).api,
};

const CSV = '﻿Aktionszeit;Kennung\r\n30.10.2026 08:15;M1\r\n';

test.describe('Protokoll: Verlauf exportieren', () => {
    useServer();
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...OVERVIEW,
            ...MASTER_DATA,
            ...delegate(() => server),
            'GET api/v1/device-events/export': {
                status: 200,
                contentType: 'text/csv; charset=utf-8',
                headers: {
                    'Content-Disposition':
                        'attachment; filename="historie-test-2026-10-01-bis-2026-10-30.csv"',
                },
                text: CSV,
            },
        },
    });

    test('Zeitraum, Gerätetyp, Standort mit Fächern und Reihenfolge', async ({
        page,
        apiRequests,
    }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await page.getByRole('tab', { name: 'Verlauf exportieren' }).click();
        await expect(page).toHaveURL(/\/protokoll\?ansicht=export$/);
        await expect(page.getByLabel('Von')).toHaveValue(localDay(-89));
        await expect(page.getByLabel('Bis (einschließlich)')).toHaveValue(localDay());
        await expect(page.getByRole('button', { name: 'Letzte 90 Tage' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );

        await page.getByRole('button', { name: 'Letzte 30 Tage' }).click();
        await expect(page.getByLabel('Von')).toHaveValue(localDay(-29));
        await page.getByRole('combobox', { name: 'Gerätetyp' }).click();
        await page.getByRole('option', { name: 'Funkgerät', exact: true }).click();
        await page.getByRole('combobox', { name: 'Standort (mit Fächern)' }).click();
        await page.getByRole('option', { name: 'HLF 20', exact: true }).click();
        await page.getByRole('radio', { name: 'Älteste zuerst' }).check();

        const download = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Verlauf herunterladen (CSV)' }).click();
        const file = await download;
        expect(file.suggestedFilename()).toBe('historie-test-2026-10-01-bis-2026-10-30.csv');
        expect(await readFile(await file.path(), 'utf8')).toBe(CSV);
        await expect(page.getByTestId('export-saved')).toContainText(
            'historie-test-2026-10-01-bis-2026-10-30.csv',
        );
        const exported = apiRequests.find((r) => r.path === 'api/v1/device-events/export');
        expect(Object.fromEntries(new URL(exported?.url ?? 'http://x/').searchParams)).toEqual({
            from: localDay(-29),
            to: localDay(),
            device_type_id: '2',
            location_id: '10',
            include_sublocations: '1',
            order: 'oldest',
        });
    });

    test('Zeitraum wird beim Tippen geprüft', async ({ page, apiRequests }) => {
        await open(page, '?ansicht=export');
        const button = page.getByRole('button', { name: 'Verlauf herunterladen (CSV)' });
        await page.getByLabel('Von').fill('2025-01-01');
        await page.getByLabel('Bis (einschließlich)').fill('2026-06-30');
        await expect(page.getByTestId('range-problem')).toHaveText(
            'Höchstens 366 Tage auf einmal (jetzt 546).',
        );
        await expect(button).toBeDisabled();
        await page.getByLabel('Bis (einschließlich)').fill('2024-12-31');
        await expect(page.getByTestId('range-problem')).toHaveText(
            'Das Enddatum liegt vor dem Startdatum.',
        );
        await page.getByLabel('Bis (einschließlich)').fill('2025-12-31');
        await expect(page.getByTestId('range-problem')).toHaveCount(0);
        await expect(button).toBeEnabled();
        expect(apiRequests.filter((r) => r.path === 'api/v1/device-events/export')).toEqual([]);
    });

    test('Handy: Export ohne Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page, '?ansicht=export');
        await expect(
            page.getByRole('button', { name: 'Verlauf herunterladen (CSV)' }),
        ).toBeVisible();
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
});

test.describe('Protokoll: Export abgelehnt', () => {
    useServer();
    test.use({
        allowedConsoleError: HTTP_ERROR_CONSOLE,
        api: {
            'GET api/v1/auth/me': ME,
            ...OVERVIEW,
            ...MASTER_DATA,
            ...delegate(() => server),
            'GET api/v1/device-events/export': {
                status: 409,
                body: {
                    error: 'rule_violation',
                    message:
                        'Der Zeitraum enthält 120000 Einträge, höchstens 100000 sind möglich. Bitte den Zeitraum verkleinern oder filtern.',
                    details: { events: 120000, max: 100000 },
                },
            },
        },
    });

    test('zu viele Einträge: Meldung des Servers statt Datei', async ({ page }) => {
        await open(page, '?ansicht=export');
        await page.getByRole('button', { name: 'Verlauf herunterladen (CSV)' }).click();
        await expect(
            page.getByText('Der Zeitraum enthält 120000 Einträge', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Verlauf herunterladen (CSV)' }),
        ).toBeEnabled();
    });
});
