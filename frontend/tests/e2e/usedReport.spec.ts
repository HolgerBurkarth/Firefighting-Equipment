import type { Page } from '@playwright/test';
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
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';
import { storedDeviceIds } from './support/offlineCopy';

/**
 * „Benutzt melden“ und Einmalgeräte (Phase 2, P2.3): Die Rolle „Geräteträger“ darf Einmalgeräte nur als
 * benutzt melden (Einschränkung `single_use_used_only`), alles andere wie bisher. Die App schickt
 * dafür `POST devices/{id}/used` ohne Standort und Status; das Ziel (Sammelstelle) gibt der Server vor.
 */

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const SEARCH = { search: 'read' } as const;
const ACTIONS = ['book.move', 'book.status', 'device.used'];

/** M1 normal, M2 ein Einmalgerät. */
const M1 = deviceJson(1, { identifier: 'M1' });
const M2 = deviceJson(2, {
    identifier: 'M2',
    device_type: { id: 2, name: 'Einmalfilter', single_use: true },
});

function me(restrictions: readonly string[]) {
    return {
        status: 200,
        body: meBody('user', 'Gerd', permissionsJson(SEARCH, ACTIONS, false, restrictions)),
    } as const;
}

/** Antwort von POST devices/{id}/used wie DeviceStateHandler: benutzt, in der Sammelstelle. */
function usedResponse(request: MockRequest): MockResponse {
    const id = Number(/devices\/(\d+)\/used$/.exec(request.path)?.[1]);
    const body = request.body as Record<string, unknown>;
    return {
        status: 200,
        body: {
            device: deviceJson(id, {
                identifier: `M${String(id)}`,
                version: 2,
                status: 'used',
                status_label: 'benutzt',
                location: { id: 40, name: 'Sammelstelle', path: 'Sammelstelle' },
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

function usedRequests(requests: readonly MockRequest[], id: number): Record<string, unknown>[] {
    return requests
        .filter((r) => r.method === 'POST' && r.path === `api/v1/devices/${String(id)}/used`)
        .map((r) => r.body as Record<string, unknown>);
}

/** Die Rückfrage vor „Benutzt melden“: Standard „Abbrechen“, bestätigt wird bewusst. */
async function confirmUsed(page: Page): Promise<void> {
    const dialog = page.getByTestId('confirm-dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Benutzt melden' }).click();
}

async function openFromSearch(page: Page, term: string, title: string): Promise<void> {
    await page.goto('/firefighting/test/suchen');
    await page.getByRole('searchbox', { name: 'Ausrüstung suchen' }).fill(term);
    await page.getByTestId('device-card').first().click();
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible();
}

test.describe('Geräteträger: Einmalgeräte nur benutzt melden', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me(['single_use_used_only']),
            'GET api/v1/devices': devicesEndpoint([M1, M2]),
            ...deviceDetail(M1),
            ...deviceDetail(M2),
            ...LOCATIONS,
            'POST api/v1/devices/1/used': usedResponse,
            'POST api/v1/devices/2/used': usedResponse,
        },
    });

    test('Einmalgerät: weder Standort noch Status, nur „Benutzt melden“', async ({
        page,
        apiRequests,
    }) => {
        await openFromSearch(page, 'M2', 'M2 · Einmalfilter');
        await expect(page.getByTestId('used-only-hint')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Standort' })).toHaveCount(0);
        await expect(page.getByRole('heading', { name: 'Status' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);

        await page.getByTestId('report-used').click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('M2 als benutzt melden?');
        await expect(dialog).toContainText('Der Standort bleibt.');
        await expect(dialog.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await dialog.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(dialog).toHaveCount(0);
        expect(usedRequests(apiRequests, 2)).toHaveLength(0);
        await page.getByTestId('report-used').click();
        await confirmUsed(page);

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('booking-notice')).toHaveText(
            '✓ M2 → Sammelstelle · benutzt',
        );
        const sent = usedRequests(apiRequests, 2);
        expect(sent).toHaveLength(1);
        expect(sent[0]).toEqual({
            version: 1,
            client_ref: expect.stringMatching(UUID_V4) as unknown,
        });
        expect(apiRequests.filter((r) => r.path === 'api/v1/devices/2/state')).toHaveLength(0);
    });

    test('Kommentar geht mit', async ({ page, apiRequests }) => {
        await openFromSearch(page, 'M2', 'M2 · Einmalfilter');
        await page.getByLabel('Kommentar (optional)').fill('Einsatz Waldbrand');
        await page.getByTestId('report-used').click();
        await confirmUsed(page);
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        expect(usedRequests(apiRequests, 2)[0]).toMatchObject({ comment: 'Einsatz Waldbrand' });
    });

    test('normale Ausrüstung: Standort und Status wie bisher, dazu „Benutzt melden“', async ({
        page,
    }) => {
        await openFromSearch(page, 'M1', 'M1 · Atemschutzmaske');
        await expect(page.getByRole('heading', { name: 'Standort' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Status' })).toBeVisible();
        await expect(page.getByTestId('used-only-hint')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeVisible();
        await expect(page.getByTestId('report-used')).toBeEnabled();
    });
});

test.describe('Rückfrage nennt die Sammelstelle', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me([]),
            'GET api/v1/devices': devicesEndpoint([M1]),
            ...deviceDetail(M1),
            ...LOCATIONS,
            'GET api/v1/client-config': {
                status: 200,
                body: { ...DEFAULT_CLIENT_CONFIG, used_target: { id: 40, name: 'Wache' } },
            },
        },
    });

    test('„Es kommt in die Sammelstelle Wache.“', async ({ page }) => {
        await openFromSearch(page, 'M1', 'M1 · Atemschutzmaske');
        await page.getByTestId('report-used').click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('M1 als benutzt melden?');
        await expect(dialog).toContainText('Es kommt in die Sammelstelle Wache.');
    });
});

test.describe('Rückfrage bei direkt geöffneter Seite', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me([]),
            'GET api/v1/devices': devicesEndpoint([M1]),
            ...deviceDetail(M1),
            ...LOCATIONS,
            'GET api/v1/client-config': {
                status: 200,
                body: { ...DEFAULT_CLIENT_CONFIG, used_target: { id: 40, name: 'Wache' } },
            },
        },
    });

    test('nennt die Sammelstelle, nie den Ersatztext', async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.removeItem('ff-used-target:test');
        });
        await page.goto('/firefighting/test/geraete/1');
        await expect(
            page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
        ).toBeVisible();
        await page.getByTestId('report-used').click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('Es kommt in die Sammelstelle Wache.');
        await expect(dialog).not.toContainText('falls es eine gibt');
    });
});

test.describe('Ohne Einschränkung und ohne die Aktion', () => {
    test.describe('Rolle ohne Einschränkung', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': me([]),
                'GET api/v1/devices': devicesEndpoint([M2]),
                ...deviceDetail(M2),
                ...LOCATIONS,
            },
        });

        test('Einmalgerät lässt sich wie jedes andere umbuchen', async ({ page }) => {
            await openFromSearch(page, 'M2', 'M2 · Einmalfilter');
            await expect(page.getByRole('heading', { name: 'Standort' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Status' })).toBeVisible();
            await expect(page.getByTestId('used-only-hint')).toHaveCount(0);
        });
    });

    test.describe('Standard-Rolle Benutzer', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('user') },
                'GET api/v1/devices': devicesEndpoint([M1]),
                ...deviceDetail(M1),
                ...LOCATIONS,
            },
        });

        test('kein Knopf „Benutzt melden“', async ({ page }) => {
            await openFromSearch(page, 'M1', 'M1 · Atemschutzmaske');
            await expect(page.getByRole('button', { name: 'Speichern' })).toBeVisible();
            await expect(page.getByTestId('report-used')).toHaveCount(0);
        });
    });

    test.describe('schon als benutzt gemeldet', () => {
        const USED = deviceJson(1, { identifier: 'M1', status: 'used', status_label: 'benutzt' });
        test.use({
            api: {
                'GET api/v1/auth/me': me([]),
                'GET api/v1/devices': devicesEndpoint([USED]),
                ...deviceDetail(USED),
                ...LOCATIONS,
            },
        });

        test('der Knopf ist gesperrt', async ({ page }) => {
            await openFromSearch(page, 'M1', 'M1 · Atemschutzmaske');
            await expect(page.getByTestId('report-used')).toBeDisabled();
            await expect(page.getByText('schon als benutzt gemeldet')).toBeVisible();
        });
    });
});

test.describe('Benutzt melden ohne Verbindung', () => {
    const state: { offline: boolean } = { offline: false };
    const online: Record<string, MockResponse | ((r: MockRequest) => MockResponse | 'abort')> = {
        'GET api/v1/auth/me': me(['single_use_used_only']),
        'GET api/v1/devices': devicesEndpoint([M2]),
        ...deviceDetail(M2),
        ...LOCATIONS,
        'POST api/v1/devices/2/used': usedResponse,
    };
    test.use({
        api: Object.fromEntries(
            Object.entries(online).map(([key, answer]) => [
                key,
                (request: MockRequest): MockResponse | 'abort' =>
                    state.offline
                        ? 'abort'
                        : typeof answer === 'function'
                          ? answer(request)
                          : answer,
            ]),
        ),
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        state.offline = false;
    });

    test('kommt in die Warteschlange und wird bei Verbindung als „used“ gesendet', async ({
        page,
        context,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/suchen');
        await expect.poll(() => storedDeviceIds(page)).toEqual([2]);
        await page.getByRole('searchbox').fill('M2');
        await page.getByTestId('device-card').click();
        await expect(page.getByTestId('used-only-hint')).toBeVisible();

        state.offline = true;
        await context.setOffline(true);
        await expect(page.getByTestId('offline-notice')).toContainText('Offline – angezeigt');
        await page.getByTestId('report-used').click();
        await confirmUsed(page);

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('queued-waiting')).toContainText('M2 → benutzt gemeldet');
        expect(usedRequests(apiRequests, 2)).toHaveLength(0);

        state.offline = false;
        await context.setOffline(false);
        await expect(page.getByTestId('queued-done')).toBeVisible();
        const sent = usedRequests(apiRequests, 2);
        expect(sent).toHaveLength(1);
        expect(sent[0]).toMatchObject({ version: 1, occurred_at: expect.any(String) });
        expect(sent[0]).not.toHaveProperty('status');
        expect(sent[0]).not.toHaveProperty('location_id');
    });
});
