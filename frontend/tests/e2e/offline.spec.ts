import type { Page } from '@playwright/test';
import {
    DATABASE_UNAVAILABLE,
    expect,
    meBody,
    NETWORK_ERROR_CONSOLE,
    NOT_AUTHENTICATED,
    permissionsJson,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';
import { storedDeviceIds } from './support/offlineCopy';
import { CURRENT_SESSION_ONLY } from './support/profile';

/**
 * Offline-Modus, Grundlage (KONZEPT.md, "Offline-Modus – Entscheidungen", 49–51): Die App merkt sich
 * die bestätigte Sitzung und eine Kopie der Daten (IndexedDB). Antwortet der Server nicht, öffnet sie
 * die Seiten mit dieser Sitzung und zeigt die Kopie mit grauem Streifen; nur eine echte Antwort (401)
 * beendet die Sitzung. „Ohne Verbindung“ heißt hier: jede Anfrage an die API scheitert (route.abort);
 * die App-Seite selbst liefert der Testserver (Service Worker sind in diesen Tests gesperrt).
 */

const DEVICES = [
    deviceJson(1, { identifier: 'M1' }),
    deviceJson(2, {
        identifier: 'M2',
        location: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
        status: 'defective',
        status_label: 'defekt',
    }),
    deviceJson(3, { identifier: 'PA 3', next_inspection_due: '2020-01-01' }),
];

type Handler = (request: MockRequest) => MockResponse;

/** Server, der je nach `state.mode` antwortet, keine Verbindung hat oder 401 meldet. */
function switchable(state: { mode: 'online' | 'offline' | 'expired' }): Record<string, Handler> {
    const online: Record<string, MockResponse | Handler> = {
        'GET api/v1/auth/me': { status: 200, body: meBody('user') },
        'GET api/v1/devices': devicesEndpoint(DEVICES),
        ...Object.fromEntries(DEVICES.flatMap((device) => Object.entries(deviceDetail(device)))),
        ...LOCATIONS,
        ...CURRENT_SESSION_ONLY,
        'POST api/v1/auth/logout': { status: 200, body: { logged_out: true } },
    };
    const routes: Record<string, Handler> = {};
    for (const [key, answer] of Object.entries(online)) {
        routes[key] = (request) => {
            if (state.mode === 'offline') {
                return 'abort';
            }
            if (state.mode === 'expired' && key !== 'POST api/v1/auth/logout') {
                return NOT_AUTHENTICATED;
            }
            return typeof answer === 'function' ? answer(request) : answer;
        };
    }
    return routes;
}

test.describe('Offline-Modus: Grundlage', () => {
    const state: { mode: 'online' | 'offline' | 'expired' } = { mode: 'online' };
    test.use({
        api: switchable(state),
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        state.mode = 'online';
    });

    async function goOfflineAndReload(page: Page): Promise<void> {
        await page.goto('/firefighting/test/suchen');
        await expect(page.getByTestId('device-card')).toHaveCount(0);
        await page.getByRole('searchbox').fill('M1');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        state.mode = 'offline';
        await page.reload();
    }

    test('ohne Verbindung: Sitzung bleibt, Suche, Gerät und Termine aus der Kopie', async ({
        page,
    }) => {
        await goOfflineAndReload(page);
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        const stripe = page.getByTestId('offline-notice');
        await expect(stripe).toContainText(
            /^Offline – angezeigt wird der Stand vom \d\d\.\d\d\., \d\d:\d\d\.$/,
        );
        await expect(page.getByTestId('connection-notice')).toHaveCount(0);

        await page.getByRole('searchbox').fill('M2');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
        await expect(page.getByTestId('device-card')).toContainText('HLF 20 / G2');
        await page.getByTestId('device-card').click();
        await expect(page).toHaveURL(/\/firefighting\/test\/geraete\/2$/);
        await expect(page.getByText('Jetzt: HLF 20 / G2 · defekt')).toBeVisible();

        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByText('PA 3')).toBeVisible();
        await expect(page.getByTestId('offline-notice')).toBeVisible();
    });

    test('ohne Verbindung sind alle aktiven Standorte wählbar (mit der Synchronisierung geladen)', async ({
        page,
    }) => {
        // Online nur „Suchen“ geöffnet, nie Umbuchen oder die Standortwahl.
        await goOfflineAndReload(page);
        await page.getByRole('searchbox').fill('M2');
        await page.getByTestId('device-card').click();
        await page.getByRole('button', { name: 'Anderen Standort suchen …' }).click();
        await expect(
            page.getByRole('heading', { level: 1, name: 'Standort wählen' }),
        ).toBeVisible();
        await expect(page.getByRole('button', { name: 'LF 10 2 Ausrüstungen' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Lager 5 Ausrüstungen' })).toBeVisible();
    });

    test('Verbindung wieder da: Streifen weg, Daten frisch', async ({ page }) => {
        await goOfflineAndReload(page);
        await expect(page.getByTestId('offline-notice')).toBeVisible();
        state.mode = 'online';
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.getByTestId('offline-notice')).toBeHidden();
        await page.getByRole('searchbox').fill('M1');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
    });

    test('lehnt der Server die Sitzung danach ab (401), zur Anmeldung', async ({ page }) => {
        await goOfflineAndReload(page);
        await expect(page.getByTestId('offline-notice')).toBeVisible();
        state.mode = 'expired';
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden\?weiter=/);
        await expect(page.getByText(/Sitzung ist abgelaufen/)).toBeVisible();
        expect(await page.evaluate(() => localStorage.getItem('ff-session:test'))).toBeNull();
        // Die Kopie bleibt für dasselbe Konto (Warteschlange und Daten nach erneuter Anmeldung).
        expect(await storedDeviceIds(page)).toEqual([1, 2, 3]);
    });

    test('Abmelden löscht Sitzung und Kopie', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        expect(await page.evaluate(() => localStorage.getItem('ff-session:test'))).not.toBeNull();
        await page.goto('/firefighting/test/profil');
        await page.getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        await expect.poll(() => storedDeviceIds(page)).toBeNull();
        expect(await page.evaluate(() => localStorage.getItem('ff-session:test'))).toBeNull();
    });

    test('ohne gespeicherte Sitzung wie bisher: Hinweis und erneuter Versuch', async ({ page }) => {
        state.mode = 'offline';
        await page.goto('/firefighting/test/suchen');
        await expect(page.getByTestId('connection-notice')).toContainText(
            'Keine Verbindung zum Server',
        );
        await expect(page.getByTestId('offline-notice')).toHaveCount(0);
    });
});

/** Antwort auf POST devices/{id}/state wie DeviceBookingJson (übernommen). */
function bookedResponse(request: MockRequest): MockResponse {
    const body = request.body as Record<string, unknown>;
    const device = DEVICES.find((d) => request.path === `api/v1/devices/${String(d['id'])}/state`);
    return {
        status: 200,
        body: {
            device: {
                ...device,
                version: Number(body['version']) + 1,
                status: body['status'] ?? device?.['status'],
                status_label: body['status'] === 'defective' ? 'defekt' : device?.['status_label'],
            },
            result: {
                outcome: 'applied',
                outcome_label: 'übernommen',
                reason: null,
                reason_label: null,
                message: 'Die Buchung wurde übernommen.',
                stale_confirmed: body['confirmed_stale'] === true,
                client_ref: body['client_ref'],
                replayed: false,
                notice_id: null,
            },
        },
    };
}

const CLIENT_CONFIG: MockResponse = {
    status: 200,
    body: {
        server_time: '2026-10-31T12:00:00Z',
        offline: { max_age_days: 7, queue_max_age_days: 7 },
        max_clock_skew_seconds: 300,
    },
};

/** Liest die Warteschlange des Kontos 7 der Stadt `test` aus IndexedDB. */
async function storedQueue(page: Page): Promise<Record<string, unknown>[] | null> {
    return page.evaluate(
        () =>
            new Promise<Record<string, unknown>[] | null>((resolve) => {
                const open = indexedDB.open('ff-offline');
                open.onupgradeneeded = () => {
                    open.transaction?.abort();
                };
                open.onerror = () => {
                    resolve(null);
                };
                open.onsuccess = () => {
                    const db = open.result;
                    const get = db.transaction('queue').objectStore('queue').get('test:7');
                    get.onsuccess = () => {
                        db.close();
                        const stored = get.result as
                            { bookings: Record<string, unknown>[] } | undefined;
                        resolve(stored?.bookings ?? null);
                    };
                };
            }),
    );
}

test.describe('Offline-Modus: Buchungen in der Warteschlange', () => {
    const state: {
        mode: 'online' | 'offline' | 'expired';
        failBooking: boolean;
        otherAccount: boolean;
    } = {
        mode: 'online',
        failBooking: false,
        otherAccount: false,
    };
    /** Ein anderes Konto (Bernd, Nr. 8) auf demselben Gerät. */
    const bernd = meBody('user', 'Bernd');
    bernd['user'] = {
        ...(bernd['user'] as Record<string, unknown>),
        id: 8,
        email: 'bernd@example.org',
    };
    const online = switchable(state);
    test.use({
        api: {
            ...online,
            'GET api/v1/auth/me': (request) =>
                state.mode === 'online' && state.otherAccount
                    ? { status: 200, body: bernd }
                    : (online['GET api/v1/auth/me']?.(request) ?? 'abort'),
            'GET api/v1/client-config': () => (state.mode === 'offline' ? 'abort' : CLIENT_CONFIG),
            'POST api/v1/devices/1/state': (request) => {
                if (state.mode === 'offline') {
                    return 'abort';
                }
                return state.failBooking ? DATABASE_UNAVAILABLE : bookedResponse(request);
            },
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        state.mode = 'online';
        state.failBooking = false;
        state.otherAccount = false;
    });

    test('anderes Konto: Buchungen des bisherigen bleiben, Hinweis und Verwerfen nach Rückfrage', async ({
        page,
    }) => {
        await bookOffline(page);
        // Server gestört: Annas Buchung bleibt sicher in der Warteschlange.
        state.mode = 'online';
        state.failBooking = true;
        state.otherAccount = true;
        await page.reload();
        await expect(page.getByText('Hallo Bernd')).toBeVisible();
        const notice = page.getByTestId('other-queue');
        await expect(notice).toHaveText(
            /Auf diesem Gerät liegt 1 nicht gesendete Buchung von Anna\. Sie wird gesendet, sobald Anna sich wieder anmeldet\./,
        );
        await expect(page.getByTestId('booking-queue')).toHaveCount(0);
        expect(await storedQueue(page)).toHaveLength(1);

        await notice.getByRole('button', { name: 'Verwerfen …' }).click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm).toContainText(
            'Die nicht gesendete Buchung von Anna wird von diesem Gerät gelöscht und nie gesendet.',
        );
        await confirm.getByRole('button', { name: 'Verwerfen' }).click();
        await expect(notice).toHaveCount(0);
        await expect.poll(() => storedQueue(page)).toBeNull();
    });

    /** Online einmal laden (Kopie mit Standorten), dann ohne Verbindung M1 als defekt buchen. */
    async function bookOffline(page: Page): Promise<void> {
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        await page.getByRole('button', { name: 'Zurück' }).click();
        // Erst nach dem Seitenwechsel neu laden (sonst lädt WebKit unter Last noch das Gerät).
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        state.mode = 'offline';
        await page.reload();
        await expect(page.getByTestId('offline-notice')).toBeVisible();
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await page.getByRole('button', { name: 'defekt', exact: true }).click();
        await page.getByLabel('Was ist defekt?').fill('Riss im Sichtfenster');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
    }

    test('offline gebucht: wartet mit Uhr, Liste zeigt den Wunsch, bei Verbindung gesendet', async ({
        page,
        apiRequests,
    }) => {
        await bookOffline(page);
        await expect(page.getByTestId('booking-notice')).toContainText(
            'Gespeichert – wird gesendet, sobald eine Verbindung besteht: M1 → defekt',
        );
        const queue = page.getByTestId('booking-queue');
        await expect(queue).toContainText('1 Buchung wartet auf Verbindung');
        await expect(queue.getByTestId('queued-waiting')).toContainText('M1 → defekt');
        await expect(queue.getByTestId('queued-waiting')).toContainText('wartet auf Verbindung');
        await page.getByRole('searchbox').fill('M1');
        await expect(page.getByTestId('device-card')).toContainText('defekt');
        expect(await storedQueue(page)).toHaveLength(1);

        state.mode = 'online';
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(queue.getByTestId('queued-done')).toContainText(
            'Die Buchung wurde übernommen.',
        );
        await expect(queue).toContainText('beim Server angekommen');
        const posted = apiRequests.filter((r) => r.path === 'api/v1/devices/1/state');
        expect(posted).toHaveLength(1);
        const body = posted[0]?.body as Record<string, unknown>;
        expect(body['status']).toBe('defective');
        expect(body['comment']).toBe('Riss im Sichtfenster');
        expect(body['version']).toBe(1);
        expect(body['occurred_at']).toMatch(/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/);
        expect(body['client_ref']).toMatch(/^[0-9a-f-]{36}$/);
        expect(body['confirmed_stale']).toBeUndefined();

        await queue.getByRole('button', { name: 'Angekommene ausblenden' }).click();
        await expect(queue).toBeHidden();
        await expect.poll(() => storedQueue(page)).toEqual([]);
    });

    test('Abmelden mit ungesendeter Buchung fragt nach und löscht dann alles', async ({ page }) => {
        await bookOffline(page);
        state.mode = 'online';
        state.failBooking = true;
        await page.goto('/firefighting/test/profil');
        await page.getByRole('button', { name: 'Abmelden' }).click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm).toContainText(
            '1 Buchung wurde noch nicht gesendet und geht beim Abmelden verloren.',
        );
        await confirm.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/profil$/);

        await page.getByRole('button', { name: 'Abmelden' }).click();
        await confirm.getByRole('button', { name: 'Abmelden' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        await expect.poll(() => storedQueue(page)).toBeNull();
        await expect.poll(() => storedDeviceIds(page)).toBeNull();
    });

    test('Daten zu alt: offline buchen gesperrt', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        // Letzte Synchronisierung vor 8 Tagen (Grenze 7).
        await page.evaluate(
            () =>
                new Promise<void>((resolve) => {
                    const open = indexedDB.open('ff-offline');
                    open.onsuccess = () => {
                        const store = open.result
                            .transaction('snapshots', 'readwrite')
                            .objectStore('snapshots');
                        const get = store.get('test:7');
                        get.onsuccess = () => {
                            const snapshot = get.result as Record<string, unknown>;
                            snapshot['syncedAt'] = new Date(
                                Date.now() - 8 * 86_400_000,
                            ).toISOString();
                            store.put(snapshot, 'test:7').onsuccess = () => {
                                resolve();
                            };
                        };
                    };
                }),
        );
        state.mode = 'offline';
        await page.reload();
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('offline-problem')).toHaveText(
            'Daten zu alt, bitte mit dem Internet verbinden.',
        );
        await expect(page).toHaveURL(/\/firefighting\/test\/geraete\/1$/);
        expect(await storedQueue(page)).toBeNull();
    });

    test('alte Buchung: erst „Trotzdem senden“, dann mit confirmed_stale', async ({
        page,
        apiRequests,
    }) => {
        await bookOffline(page);
        // Die Buchung wartet seit 8 Tagen (Grenze 7).
        await page.evaluate(
            () =>
                new Promise<void>((resolve) => {
                    const open = indexedDB.open('ff-offline');
                    open.onsuccess = () => {
                        const store = open.result
                            .transaction('queue', 'readwrite')
                            .objectStore('queue');
                        const get = store.get('test:7');
                        get.onsuccess = () => {
                            const queue = get.result as { bookings: Record<string, unknown>[] };
                            const old = new Date(Date.now() - 8 * 86_400_000);
                            for (const entry of queue.bookings) {
                                entry['occurredAt'] = `${old.toISOString().slice(0, 19)}Z`;
                            }
                            store.put(queue, 'test:7').onsuccess = () => {
                                resolve();
                            };
                        };
                    };
                }),
        );
        state.mode = 'online';
        await page.reload();
        const queue = page.getByTestId('booking-queue');
        await expect(queue.getByTestId('queued-stale')).toContainText(
            'Diese Buchung wartet seit mehr als 7 Tagen.',
        );
        expect(apiRequests.filter((r) => r.path === 'api/v1/devices/1/state')).toHaveLength(0);
        await queue.getByRole('button', { name: 'Trotzdem senden' }).click();
        await expect(queue.getByTestId('queued-done')).toBeVisible();
        const posted = apiRequests.filter((r) => r.path === 'api/v1/devices/1/state');
        expect((posted[0]?.body as Record<string, unknown>)['confirmed_stale']).toBe(true);
    });

    test('Verbindung bricht beim Speichern ab: Buchung kommt in die Warteschlange', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();
        state.mode = 'offline';
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('booking-queue')).toContainText('M1 → in Wartung');
    });

    test('Datenbank ohne Tabellen (von anderer Stelle angelegt): die App legt sie an', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/anmelden');
        await page.evaluate(
            () =>
                new Promise<void>((resolve) => {
                    const open = indexedDB.open('ff-offline');
                    open.onsuccess = () => {
                        open.result.close();
                        resolve();
                    };
                }),
        );
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        // Die Datenbank hat jetzt eine höhere Version; auch der nächste Seitenaufruf öffnet sie
        // (live 01.11.2026: mit fester Version 1 scheiterte jedes Öffnen, die Kopie blieb alt).
        state.mode = 'offline';
        await page.reload();
        await expect(page.getByTestId('offline-notice')).toBeVisible();
        await page.getByRole('searchbox').fill('M1');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
    });
});

test.describe('Offline-Modus: Browser meldet offline', () => {
    const state: { mode: 'online' | 'offline' | 'expired' } = { mode: 'online' };
    test.use({
        api: {
            ...switchable(state),
            'GET api/v1/client-config': CLIENT_CONFIG,
            'POST api/v1/devices/1/state': (request) =>
                state.mode === 'offline' ? 'abort' : bookedResponse(request),
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        state.mode = 'online';
    });

    test('Streifen sofort, Buchung ohne Sendeversuch in die Warteschlange, bei online gesendet', async ({
        page,
        context,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3]);
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();

        state.mode = 'offline';
        await context.setOffline(true);
        await expect(page.getByTestId('offline-notice')).toContainText('Offline – angezeigt');
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('queued-waiting')).toContainText('M1 → in Wartung');
        expect(apiRequests.filter((r) => r.path === 'api/v1/devices/1/state')).toHaveLength(0);

        state.mode = 'online';
        await context.setOffline(false);
        await expect(page.getByTestId('queued-done')).toBeVisible();
        await expect(page.getByTestId('offline-notice')).toBeHidden();
        expect(apiRequests.filter((r) => r.path === 'api/v1/devices/1/state')).toHaveLength(1);
    });
});

test.describe('Offline-Modus: Tausch (Phase 2, P2.9)', () => {
    const state: { mode: 'online' | 'offline' | 'expired' } = { mode: 'online' };
    /** Ersatz im Lager (anderer Standort der Ebene 1 als M1, sonst bietet die Liste ihn nicht an). */
    const M4 = deviceJson(4, {
        identifier: 'M4',
        location: { id: 30, name: 'Lager', path: 'Lager' },
    });
    const ALL = [...DEVICES, M4];
    const base = switchable(state);

    function whenOnline(answer: MockResponse | ((request: MockRequest) => MockResponse)) {
        return (request: MockRequest): MockResponse =>
            state.mode === 'offline'
                ? 'abort'
                : typeof answer === 'function'
                  ? answer(request)
                  : answer;
    }

    test.use({
        api: {
            ...base,
            'GET api/v1/auth/me': whenOnline({
                status: 200,
                body: meBody(
                    'user',
                    'Gerd',
                    permissionsJson({ search: 'read' }, [
                        'book.move',
                        'book.status',
                        'device.swap',
                    ]),
                ),
            }),
            'GET api/v1/devices': whenOnline(devicesEndpoint(ALL)),
            ...Object.fromEntries(
                ALL.flatMap((device) =>
                    Object.entries(deviceDetail(device)).map(
                        ([key, answer]) => [key, whenOnline(answer)] as const,
                    ),
                ),
            ),
            'GET api/v1/client-config': CLIENT_CONFIG,
            'POST api/v1/devices/swap': whenOnline((request) => {
                const body = request.body as { out: { id: number }; in: { id: number } };
                const gone = ALL.find((d) => d['id'] === body.out.id);
                const back = ALL.find((d) => d['id'] === body.in.id);
                return {
                    status: 200,
                    body: {
                        out: { ...gone, version: 2, status: 'defective', status_label: 'defekt' },
                        in: { ...back, version: 2, location: gone?.['location'] },
                        swap_id: 'a'.repeat(32),
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
                };
            }),
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        state.mode = 'online';
    });

    test('ohne Verbindung als eine Einheit in die Warteschlange, bei online gesendet', async ({
        page,
        context,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3, 4]);
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();

        state.mode = 'offline';
        await context.setOffline(true);
        await expect(page.getByTestId('offline-notice')).toContainText('Offline – angezeigt');
        await page.getByTestId('swap-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByRole('radio', { name: /M4/ }).check();
        await dialog.getByRole('button', { name: 'Defekt' }).click();
        await dialog.getByRole('button', { name: 'Tauschen', exact: true }).click();

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('booking-notice')).toContainText(
            'wird gesendet, sobald eine Verbindung besteht: M1 ⇄ M4 · getauscht',
        );
        await expect(page.getByTestId('queued-waiting')).toContainText('M1 ⇄ M4 · getauscht');
        expect(apiRequests.filter((r) => r.path === 'api/v1/devices/swap')).toHaveLength(0);

        state.mode = 'online';
        await context.setOffline(false);
        await expect(page.getByTestId('queued-done')).toBeVisible();
        const sent = apiRequests.filter((r) => r.path === 'api/v1/devices/swap');
        expect(sent).toHaveLength(1);
        expect(sent[0]?.body).toMatchObject({
            out: { id: 1, version: 1 },
            in: { id: 4, version: 1 },
            out_status: 'defective',
            client_ref: expect.any(String),
            occurred_at: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/),
        });
    });

    test('Verbindung bricht beim Tauschen ab: Warteschlange mit derselben Kennung', async ({
        page,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2, 3, 4]);
        await page.getByRole('searchbox').fill('M1');
        await page.getByTestId('device-card').click();
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();

        await page.getByTestId('swap-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByRole('radio', { name: /M4/ }).check();
        await dialog.getByRole('button', { name: 'Defekt' }).click();
        // Die Verbindung bricht ab, bevor der Tausch gesendet wird: kein Streifen „Offline“, nur die Anfrage scheitert.
        state.mode = 'offline';
        await dialog.getByRole('button', { name: 'Tauschen', exact: true }).click();

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('queued-waiting')).toContainText('M1 ⇄ M4 · getauscht');
        const first = apiRequests.filter((r) => r.path === 'api/v1/devices/swap');
        expect(first).toHaveLength(1);
        expect(first[0]?.body).not.toHaveProperty('occurred_at');
        const ref = (first[0]?.body as { client_ref: string }).client_ref;

        state.mode = 'online';
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.getByTestId('queued-done')).toBeVisible();
        const sent = apiRequests.filter((r) => r.path === 'api/v1/devices/swap');
        expect(sent).toHaveLength(2);
        expect(sent[1]?.body).toMatchObject({
            client_ref: ref,
            occurred_at: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/),
        });
    });
});
