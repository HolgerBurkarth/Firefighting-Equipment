import type { CDPSession, Page } from '@playwright/test';
import {
    DEFAULT_CLIENT_CONFIG,
    expect,
    meBody,
    NETWORK_ERROR_CONSOLE,
    permissionsJson,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, locationJson } from './support/devices';
import { storedDeviceIds } from './support/offlineCopy';
import { CURRENT_SESSION_ONLY } from './support/profile';

/**
 * Neuladen ohne Netz, dann offline umbuchen (Live-Lauf 11.11.2026: Absturz des Browsers bei „Speichern“ mit
 * freigeschalteten Abläufen Lager und Sammelstelle). Dieselbe Reihenfolge wie dort: App geladen und Kopie
 * vorhanden → offline → Seite neu laden (Service Worker) → Ausrüstung öffnen → ändern → „Speichern“.
 * Der Speicher der Seite wird währenddessen gemessen (`JSHeapUsedSize`); wächst er über die Grenze, bricht der
 * Test ab, statt den Rechner zu füllen.
 */

const HEAP_LIMIT_BYTES = 600 * 1024 * 1024;
const state: { offline: boolean } = { offline: false };

const DEVICES = [deviceJson(1, { identifier: 'M1' }), deviceJson(2, { identifier: 'M2' })];

function locations(): MockResponse {
    const store = {
        ...locationJson(30, 'Gerätehaus', [], 'room', 5),
        task: 'store',
        is_default_store: true,
    };
    const collection = {
        ...locationJson(40, 'Kleiderkammer', [], 'room', 0),
        task: 'collection',
        collection_accepts: 'both',
    };
    return {
        status: 200,
        body: {
            locations: [
                locationJson(10, 'HLF 20', [{ id: 11, name: 'G1', count: 6 }]),
                locationJson(20, 'LF 10', [], 'vehicle', 2),
                store,
                collection,
            ],
        },
    };
}

const online: Record<string, MockResponse | ((request: MockRequest) => MockResponse)> = {
    'GET api/v1/auth/me': {
        status: 200,
        body: meBody(
            'user',
            'Gerd',
            permissionsJson({ search: 'read', due: 'read' }, [
                'book.move',
                'book.status',
                'device.used',
            ]),
        ),
    },
    'GET api/v1/devices': devicesEndpoint(DEVICES),
    ...Object.fromEntries(DEVICES.flatMap((device) => Object.entries(deviceDetail(device)))),
    'GET api/v1/locations': locations(),
    'GET api/v1/client-config': {
        status: 200,
        body: { ...DEFAULT_CLIENT_CONFIG, used_target: { id: 40, name: 'Kleiderkammer' } },
    },
    ...CURRENT_SESSION_ONLY,
};

async function watchHeap(
    page: Page,
): Promise<{ cdp: CDPSession; peak: () => number; stop: () => void }> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Performance.enable');
    let peak = 0;
    const timer = setInterval(() => {
        void cdp
            .send('Performance.getMetrics')
            .then(({ metrics }) => {
                const used = metrics.find((m) => m.name === 'JSHeapUsedSize')?.value ?? 0;
                peak = Math.max(peak, used);
                if (used > HEAP_LIMIT_BYTES) {
                    void page.close();
                }
            })
            .catch(() => undefined);
    }, 250);
    return {
        cdp,
        peak: () => peak,
        stop: () => {
            clearInterval(timer);
        },
    };
}

test.describe('Neuladen ohne Netz, dann offline buchen', () => {
    test.use({
        serviceWorkers: 'allow',
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

    const VARIANTS: Record<string, (page: Page) => Promise<void>> = {
        Standort: async (page) => {
            await page.getByRole('button', { name: 'Anderen Standort suchen' }).click();
            await page.getByRole('button', { name: /LF 10/ }).click();
            await page.getByRole('button', { name: 'Speichern' }).click();
        },
        Status: async (page) => {
            await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
            await page.getByRole('button', { name: 'Speichern' }).click();
        },
        'Benutzt melden': async (page) => {
            await page.getByTestId('report-used').click();
            await page
                .getByTestId('confirm-dialog')
                .getByRole('button', { name: 'Benutzt melden' })
                .click();
        },
    };
    for (const [variant, act] of Object.entries(VARIANTS)) {
        test(`${variant}: kein Absturz, Buchung wartet in der Warteschlange`, async ({
            page,
            context,
            apiRequests,
        }) => {
            await page.goto('/firefighting/test/suchen');
            await page.evaluate(async () => {
                await navigator.serviceWorker.ready;
            });
            await page.reload();
            await expect
                .poll(() => page.evaluate(() => navigator.serviceWorker.controller !== null))
                .toBe(true);
            await expect.poll(() => storedDeviceIds(page)).toEqual([1, 2]);
            const heap = await watchHeap(page);

            state.offline = true;
            await context.setOffline(true);
            await page.reload();
            await expect(page.getByTestId('offline-notice')).toContainText('Offline – angezeigt');

            // Wie der Live-Lauf: C3 (Suche), dann Adresse „suchen“ erneut ohne Netz und die Ausrüstung öffnen.
            await page.getByRole('searchbox').fill('M1');
            await expect(page.getByTestId('device-card')).toBeVisible();
            await page.goto('/firefighting/test/suchen');
            await page.getByRole('searchbox').fill('M1');
            await page.getByTestId('device-card').click();
            await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();
            await act(page);
            await expect(page.getByTestId('queued-waiting')).toContainText('M1 →');
            expect(apiRequests.filter((r) => r.path === 'api/v1/devices/1/state')).toHaveLength(0);
            heap.stop();
            expect(heap.peak()).toBeLessThan(HEAP_LIMIT_BYTES);
            await context.setOffline(false);
        });
    }
});
