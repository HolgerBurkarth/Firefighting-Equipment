import { readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    NETWORK_ERROR_CONSOLE,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { barcode, fakeCamera } from './support/camera';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';
import { storedDeviceIds } from './support/offlineCopy';
import { CURRENT_SESSION_ONLY } from './support/profile';

test.use({ serviceWorkers: 'allow' });

const ASSETS = fileURLToPath(new URL('../../dist/assets/', import.meta.url));

/** Name einer Datei des Builds in assets/ (mit Hash), z. B. assetName('UsersPage', '.js'). */
function assetName(prefix: string, extension: string): string {
    const name = readdirSync(ASSETS).find(
        (file) => file.startsWith(`${prefix}-`) && file.endsWith(extension),
    );
    if (name === undefined) {
        throw new Error(`${prefix}-…${extension} fehlt im Build.`);
    }
    return name;
}

/** Dateien eines Caches als Pfad relativ zum Bereich (ohne Query, z. B. assets/SearchPage-….js). */
function cachedPaths(page: Page, cacheName: 'precache' | 'ff-assets'): Promise<string[]> {
    return page.evaluate(async (wanted) => {
        const names = await caches.keys();
        const name = names.find((n) =>
            wanted === 'precache' ? n.startsWith('workbox-precache') : n === wanted,
        );
        if (name === undefined) {
            return [];
        }
        const keys = await (await caches.open(name)).keys();
        const base = new URL(document.baseURI).pathname;
        return keys.map((request) => new URL(request.url).pathname.slice(base.length)).sort();
    }, cacheName);
}

/** Wartet, bis der Service Worker aktiv ist, und lädt neu, damit er die Seite steuert. */
async function controlledBy(page: Page): Promise<void> {
    await page.evaluate(async () => {
        await navigator.serviceWorker.ready;
    });
    await page.reload();
    await expect
        .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
        .toBe(true);
}

test.describe('Service Worker', () => {
    test('wird aus sw.js mit dem Bereich als Scope registriert', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        const registration = await page.evaluate(async () => {
            const ready = await navigator.serviceWorker.ready;
            return { scope: ready.scope, script: ready.active?.scriptURL ?? '' };
        });
        expect(new URL(registration.scope).pathname).toBe('/firefighting/test/');
        expect(new URL(registration.script).pathname).toBe('/firefighting/test/sw.js');
    });

    test.describe('Adressen des Claude-Zugangs', () => {
        // Antworten mit 404/405 meldet der Browser in der Konsole. Die Seite „Claude verbinden“ prüft
        // zuerst die Anfrage; Anfragen einer vom Service Worker kontrollierten Seite gibt WebKit nicht
        // an page.route(), dort antwortet der Testserver ohne Datenbank (503).
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                'POST api/v1/oauth/authorize/check': {
                    status: 400,
                    body: { error: 'invalid_request', message: 'Test.' },
                },
            },
        });

        test('beantwortet immer der Server, auch mit installiertem Service Worker', async ({
            page,
        }) => {
            await page.goto('/firefighting/test/anmelden');
            await page.evaluate(async () => {
                await navigator.serviceWorker.ready;
            });

            // Ohne Datenbank: 405 bzw. 404 kommen vom Router, vor dem Laden der Stadt. Mit dem
            // Rückfall des Service Workers wären es 200 und die App-Seite.
            const expected: [string, number][] = [
                ['mcp', 405],
                ['oauth/register', 405],
                ['.well-known/gibt-es-nicht', 404],
            ];
            for (const [path, status] of expected) {
                const response = await page.goto(`/firefighting/test/${path}`);
                expect(response?.status(), path).toBe(status);
                expect(response?.headers()['content-type'], path).toBe(
                    'application/json; charset=utf-8',
                );
            }

            // claude-verbinden ist eine Seite der App, kommt aber immer frisch vom Server (nicht aus
            // dem Rückfall des Service Workers), damit sie nach einem Deploy nie veraltet ist.
            const connect = await page.goto(
                '/firefighting/test/claude-verbinden?response_type=code&client_id=x',
            );
            expect(connect?.status()).toBe(200);
            expect(connect?.headers()['content-type']).toBe('text/html; charset=utf-8');
            expect(connect?.fromServiceWorker()).toBe(false);
            await expect(
                page.getByRole('heading', { level: 1, name: 'Claude verbinden' }),
            ).toBeVisible();
            await expect(page.getByTestId('claude-check-error')).toBeVisible();
        });
    });

    test('liefert die App-Seite auch offline', async ({ page, context, browserName }) => {
        // context.setOffline() umgeht in Firefox und WebKit den Service Worker (Playwright).
        test.skip(browserName !== 'chromium', 'Offline-Simulation nur in Chromium zuverlässig');
        // Öffentliche Seite (ohne auth/me), damit die Anfragen offline nur die App-Seite betreffen.
        await page.goto('/firefighting/test/anmelden');
        await page.evaluate(async () => {
            await navigator.serviceWorker.ready;
        });
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();

        await context.setOffline(true);
        await page.goto('/firefighting/test/passwort-vergessen-gibt-es-noch-nicht');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
        await context.setOffline(false);
    });

    test.describe('Vorab-Laden', () => {
        // Anfragen einer vom Service Worker kontrollierten Seite gibt WebKit nicht an page.route(); die
        // Anmeldeseite fragt dann den Testserver ohne Datenbank (503 in der Konsole).
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('lädt vorab nur die Offline-Seiten, die Konsole bei Bedarf (T15)', async ({
            page,
        }) => {
            await page.goto('/firefighting/test/anmelden');
            await controlledBy(page);

            const precached = await cachedPaths(page, 'precache');
            for (const prefix of [
                'SearchPage',
                'DevicePage',
                'InspectionsPage',
                'ProfilePage',
                'CodeScanner',
                'barcodeReader',
                'HomePage',
                'LoginPage',
                'NotFoundPage',
                'AdminDashboardPage',
                'PasswordResetPage',
                'EmailVerifyPage',
                'InvitationAcceptPage',
                'RegisterPage',
            ]) {
                expect(precached, prefix).toContain(`assets/${assetName(prefix, '.js')}`);
            }
            expect(precached).toContain(`assets/${assetName('zxing_reader', '.wasm')}`);
            expect(precached).toContain('index.html');
            // Eine CSS-Datei für die ganze App (cssCodeSplit: false), von index.html verlinkt.
            expect(precached).toContain(`assets/${assetName('style', '.css')}`);
            const users = `assets/${assetName('UsersPage', '.js')}`;
            expect(precached).not.toContain(users);
            // Vorher alle 121 Dateien des Builds; jetzt 51 (T15).
            expect(precached.length).toBeLessThan(60);

            // Eine Seite der Konsole legt der Service Worker beim ersten Laden ab (nur 200er).
            await page.evaluate(async (path) => {
                await fetch(new URL(path, document.baseURI));
            }, users);
            await expect.poll(() => cachedPaths(page, 'ff-assets')).toEqual([users]);
        });
    });
});

test.describe('Service Worker: Handy-Seiten offline (T15)', () => {
    // context.setOffline() umgeht in Firefox und WebKit den Service Worker (Playwright).
    const state: { offline: boolean } = { offline: false };
    const devices = [
        deviceJson(1, { identifier: 'M1' }),
        deviceJson(2, { identifier: 'PA 3', next_inspection_due: '2020-01-01' }),
    ];
    const online: Record<string, MockResponse | ((request: MockRequest) => MockResponse)> = {
        'GET api/v1/auth/me': { status: 200, body: meBody('user') },
        'GET api/v1/devices': devicesEndpoint(devices),
        ...Object.fromEntries(devices.flatMap((device) => Object.entries(deviceDetail(device)))),
        ...LOCATIONS,
        ...CURRENT_SESSION_ONLY,
    };
    test.use({
        api: Object.fromEntries(
            Object.entries(online).map(([key, answer]) => [
                key,
                (request: MockRequest): MockResponse =>
                    state.offline
                        ? 'abort'
                        : typeof answer === 'function'
                          ? answer(request)
                          : answer,
            ]),
        ),
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(({ browserName }) => {
        test.skip(browserName !== 'chromium', 'Offline-Simulation nur in Chromium zuverlässig');
        state.offline = false;
    });

    test('Suchen, Scanner, Umbuchen, Termine und Profil ohne Netz aus dem Vorab-Laden', async ({
        page,
        context,
    }) => {
        await fakeCamera(page, { kind: 'code', symbol: await barcode('M1', 'Code128') });
        await page.goto('/firefighting/test/suchen');
        await controlledBy(page);
        await page.getByRole('searchbox').fill('M1');
        await expect(page.getByTestId('device-card')).toHaveCount(1);
        await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2]);
        state.offline = true;
        await context.setOffline(true);

        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByText('PA 3')).toBeVisible();
        await expect(page.getByTestId('offline-notice')).toBeVisible();

        await page.goto('/firefighting/test/profil');
        await expect(page.getByRole('heading', { level: 1, name: 'Profil' })).toBeVisible();
        await expect(page.getByRole('heading', { level: 2, name: 'Anzeigemodus' })).toBeVisible();

        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();
        await context.setOffline(false);
    });
});
