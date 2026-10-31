import type { Page } from '@playwright/test';
import {
    expect,
    meBody,
    NETWORK_ERROR_CONSOLE,
    NOT_AUTHENTICATED,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';
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

/** Wartet, bis die lokale Kopie des Kontos 7 der Stadt `test` gespeichert ist; liefert die IDs. */
async function storedDeviceIds(page: Page): Promise<number[] | null> {
    return page.evaluate(
        () =>
            new Promise<number[] | null>((resolve) => {
                const open = indexedDB.open('ff-offline');
                open.onerror = () => {
                    resolve(null);
                };
                open.onsuccess = () => {
                    const db = open.result;
                    if (!db.objectStoreNames.contains('snapshots')) {
                        db.close();
                        resolve(null);
                        return;
                    }
                    const get = db.transaction('snapshots').objectStore('snapshots').get('test:7');
                    get.onsuccess = () => {
                        const value = get.result as { devices?: { id: number }[] } | undefined;
                        db.close();
                        resolve(value?.devices?.map((device) => device.id) ?? null);
                    };
                    get.onerror = () => {
                        db.close();
                        resolve(null);
                    };
                };
            }),
    );
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

    test('ohne Verbindung: Sitzung bleibt, Suche, Gerät und Prüffristen aus der Kopie', async ({
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
