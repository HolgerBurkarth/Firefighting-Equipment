import type { Page, Route } from '@playwright/test';
import { expect, HTTP_ERROR_CONSOLE, meBody, permissionsJson, test } from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';

/**
 * Schnellstart (Plan Phase 1, Schritt 1.7d): Angemeldete starten mit der gespeicherten Sitzung und der
 * Offline-Kopie, ohne auf auth/me zu warten (Ziel 2); die erste Seite lädt gleichzeitig mit tenant
 * und auth/me (Ziel 3). auth/me hält der Test über eine eigene Route zurück (sie hat Vorrang vor der
 * simulierten API aus fixtures.ts).
 */

/** Antwort auf auth/me ohne gültige Sitzung (wie NOT_AUTHENTICATED in fixtures.ts). */
const NOT_LOGGED_IN = { error: 'not_authenticated', message: 'Bitte melden Sie sich an.' };

const DEVICES = [deviceJson(1, { identifier: 'M1' }), deviceJson(2, { identifier: 'M2' })];

/** Hält auth/me zurück, bis `release` mit einer Antwort aufgerufen wird. */
async function holdMe(
    page: Page,
): Promise<{ release: (status: number, body: unknown) => void; requested: () => boolean }> {
    let requested = false;
    let answer: (status: number, body: unknown) => void = () => undefined;
    const answered = new Promise<{ status: number; body: unknown }>((resolve) => {
        answer = (status, body) => {
            resolve({ status, body });
        };
    });
    await page.route('**/api/v1/auth/me', async (route: Route) => {
        requested = true;
        const { status, body } = await answered;
        await route.fulfill({ status, json: body, headers: { 'Cache-Control': 'no-store' } });
    });
    return { release: answer, requested: () => requested };
}

/** Lokale Kopie des Kontos 7 der Stadt `test` (IDs), ohne die Datenbank anzulegen. */
function storedDeviceIds(page: Page): Promise<number[] | null> {
    return page.evaluate(
        () =>
            new Promise<number[] | null>((resolve) => {
                const open = indexedDB.open('ff-offline');
                open.onupgradeneeded = () => {
                    open.transaction?.abort();
                };
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

test.describe('Schnellstart: Angemeldete starten sofort', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('user') },
            'GET api/v1/devices': devicesEndpoint(DEVICES),
            ...Object.fromEntries(
                DEVICES.flatMap((device) => Object.entries(deviceDetail(device))),
            ),
            ...LOCATIONS,
            // Prüffristen ohne Recht auf die Suche fragen den Server (Test mit neuen Rechten).
            'GET api/v1/inspection-due': {
                status: 200,
                body: {
                    today: '2026-11-05',
                    days: 30,
                    until: '2026-12-05',
                    overdue: { devices: [], total: 0 },
                    due_soon: { devices: [], total: 0 },
                },
            },
        },
        // 401 von auth/me meldet der Browser als Fehler (Tests mit abgelehnter Sitzung).
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    /** Erster Besuch: Sitzung und Kopie werden gespeichert. */
    async function visitOnce(page: Page): Promise<void> {
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('searchbox').fill('M');
        await expect(page.getByTestId('device-card')).toHaveCount(2);
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2]);
    }

    test('mit gespeicherter Sitzung: Suchen mit Daten der Kopie, bevor auth/me antwortet', async ({
        page,
    }) => {
        await visitOnce(page);
        // Geräte vom Server kommen beim nächsten Start nicht (die Kopie muss genügen).
        await page.route('**/api/v1/devices?**', () => undefined);
        const me = await holdMe(page);
        await page.reload();

        await expect(page.getByRole('searchbox')).toBeVisible();
        await page.getByRole('searchbox').fill('M2');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
        expect(me.requested()).toBe(true);
        // Kein Hinweis: Die Sitzung wird nur im Hintergrund geprüft, der Server ist erreichbar.
        await expect(page.getByTestId('offline-notice')).toHaveCount(0);
        await expect(page.getByTestId('connection-notice')).toHaveCount(0);

        me.release(200, meBody('user'));
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('device-card')).toHaveCount(1);
    });

    test('lehnt der Server die gespeicherte Sitzung ab (401): zur Anmeldung', async ({ page }) => {
        await visitOnce(page);
        const me = await holdMe(page);
        await page.reload();
        await expect(page.getByRole('searchbox')).toBeVisible();

        me.release(401, NOT_LOGGED_IN);
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden\?weiter=/);
        await expect(page.getByText(/Sitzung ist abgelaufen/)).toBeVisible();
        expect(await page.evaluate(() => localStorage.getItem('ff-session:test'))).toBeNull();
    });

    test('andere Rechte in der Antwort: Seite nicht mehr erlaubt, still zur Startseite', async ({
        page,
    }) => {
        await visitOnce(page);
        const me = await holdMe(page);
        await page.reload();
        await expect(page.getByRole('searchbox')).toBeVisible();

        // Die Rolle darf inzwischen nur noch Prüffristen ansehen.
        me.release(200, meBody('user', undefined, permissionsJson({ due: 'read' }, [])));
        await expect(page).toHaveURL(/\/firefighting\/test\/prueffristen$/);
        await expect(page.getByRole('link', { name: 'Suchen' })).toHaveCount(0);
    });
});

test.describe('Schnellstart: Seite lädt parallel', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

    test('ohne gespeicherte Sitzung: Programmteil der Seite schon vor der Antwort von auth/me', async ({
        page,
    }) => {
        const me = await holdMe(page);
        const chunks: string[] = [];
        page.on('request', (request) => {
            if (/\/assets\/SearchPage-[^/]+\.js$/.test(request.url())) {
                chunks.push(request.url());
            }
        });
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => chunks.length).toBeGreaterThan(0);
        expect(me.requested()).toBe(true);
        // Ohne Sitzung zur Anmeldung (die Seite erscheint erst danach).
        me.release(401, NOT_LOGGED_IN);
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden\?weiter=/);
    });
});
