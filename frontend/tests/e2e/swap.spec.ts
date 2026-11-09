import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    permissionsJson,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';

/**
 * Sollbestand und Tausch (Phase 2, P2.8): Eine Buchung, die den Sollbestand unterschreiten würde, lehnt der
 * Server mit 409 ab (details.stock); die Seite bietet dann „Tauschen …“ und, mit stock.override, „Trotzdem
 * buchen …“ an. POST devices/swap bucht Ausrüstung heraus und Ersatz hinein in einer Anfrage.
 */

const SEARCH = { search: 'read' } as const;

const M1 = deviceJson(1, { identifier: 'M1' });
const M9 = deviceJson(9, {
    identifier: 'M9',
    location: { id: 7, name: 'Lager', path: 'Lager' },
});
const M8 = deviceJson(8, {
    identifier: 'M8',
    location: { id: 20, name: 'LF 10', path: 'LF 10' },
});
const F5 = deviceJson(5, {
    identifier: 'F5',
    device_type: { id: 2, name: 'Funkgerät' },
    location: { id: 7, name: 'Lager', path: 'Lager' },
});

function me(actions: readonly string[]) {
    return { status: 200, body: meBody('user', 'Gerd', permissionsJson(SEARCH, actions)) } as const;
}

function stockError(canOverride: boolean): MockResponse {
    return {
        status: 409,
        body: {
            error: 'rule_violation',
            message: 'Am Standort „HLF 20“ blieben nach dieser Buchung nur 1 von mindestens 2.',
            details: {
                stock: {
                    location: { id: 10, name: 'HLF 20', path: 'HLF 20' },
                    rule: { id: 1, min_count: 2, types: [{ id: 1, name: 'Atemschutzmaske' }] },
                    have: 1,
                    missing: 1,
                    can_override: canOverride,
                },
            },
        },
    };
}

async function openFromSearch(page: Page): Promise<void> {
    await page.goto('/firefighting/test/suchen');
    await page.getByRole('searchbox', { name: 'Ausrüstung suchen' }).fill('M1');
    await page.getByTestId('device-card').first().click();
    await expect(
        page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
    ).toBeVisible();
}

function swapped(request: MockRequest): MockResponse {
    const body = request.body as { out: { id: number }; in: { id: number } };
    return {
        status: 200,
        body: {
            out: deviceJson(body.out.id, {
                identifier: 'M1',
                version: 2,
                status: 'used',
                status_label: 'benutzt',
            }),
            in: deviceJson(body.in.id, { identifier: 'M9', version: 2 }),
            swap_id: 'a'.repeat(32),
        },
    };
}

test.describe('Sollbestand: Tauschen statt Fehlermeldung', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': me(['book.move', 'book.status', 'device.swap']),
            'GET api/v1/devices': devicesEndpoint([M1, M9, M8, F5]),
            ...deviceDetail(M1),
            ...LOCATIONS,
            'POST api/v1/devices/1/state': () => stockError(false),
            'POST api/v1/devices/swap': swapped,
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('409 zeigt den Hinweis, „Tauschen …“ bucht beide in einer Anfrage', async ({
        page,
        apiRequests,
    }) => {
        await openFromSearch(page);
        await page.getByRole('button', { name: 'In Wartung' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();

        const block = page.getByTestId('stock-block');
        await expect(block).toContainText(
            'Am Standort „HLF 20“ blieben nach dieser Buchung nur 1 von mindestens 2',
        );
        await expect(page.getByTestId('stock-override')).toHaveCount(0);

        await page.getByTestId('stock-swap').click();
        const dialog = page.getByTestId('form-dialog');
        await expect(dialog).toContainText('M1 tauschen');
        // Nur die Typen der Regel (Atemschutzmaske), das Funkgerät F5 wird nicht angeboten.
        const options = dialog.getByTestId('swap-candidates').getByRole('listitem');
        await expect(options).toHaveCount(1);
        await expect(options.first()).toContainText('M9');
        // Ausrüstung eines anderen Fahrzeugs erst hinter „Andere Standorte anzeigen“.
        await expect(dialog.getByTestId('swap-others')).toHaveCount(0);
        await dialog.getByTestId('swap-show-others').click();
        await expect(dialog.getByTestId('swap-others')).toContainText('M8');
        await expect(dialog.getByRole('button', { name: 'Tauschen', exact: true })).toBeDisabled();

        await dialog.getByRole('radio', { name: /M9/ }).check();
        await dialog.getByRole('radio', { name: 'defekt' }).check();
        await dialog.getByLabel('Kommentar (optional)').fill('Maske gerissen');
        await dialog.getByRole('button', { name: 'Tauschen', exact: true }).click();

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('booking-notice')).toContainText('M1 ⇄ M9 · getauscht');
        const sent = apiRequests.filter(
            (r) => r.method === 'POST' && r.path === 'api/v1/devices/swap',
        );
        expect(sent).toHaveLength(1);
        expect(sent[0]?.body).toEqual({
            out: { id: 1, version: 1 },
            in: { id: 9, version: 1 },
            out_status: 'defective',
            comment: 'Maske gerissen',
        });
    });

    test('„Tauschen …“ gibt es auch ohne Fehler', async ({ page }) => {
        await openFromSearch(page);
        await expect(page.getByTestId('swap-open')).toBeEnabled();
    });
});

test.describe('Sollbestand: Trotzdem buchen mit stock.override', () => {
    const bookings: Record<string, unknown>[] = [];
    test.use({
        api: {
            'GET api/v1/auth/me': me(['book.move', 'book.status', 'stock.override']),
            'GET api/v1/devices': devicesEndpoint([M1]),
            ...deviceDetail(M1),
            ...LOCATIONS,
            'POST api/v1/devices/1/state': (request: MockRequest): MockResponse => {
                const body = request.body as Record<string, unknown>;
                bookings.push(body);
                if (body['confirmed_below_minimum'] !== true) {
                    return stockError(true);
                }
                return {
                    status: 200,
                    body: {
                        device: deviceJson(1, {
                            identifier: 'M1',
                            version: 2,
                            status: 'maintenance',
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
                            notice_id: 5,
                        },
                    },
                };
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('ohne die Aktion device.swap kein „Tauschen …“; Rückfrage, dann mit Bestätigung gesendet', async ({
        page,
    }) => {
        bookings.length = 0;
        await openFromSearch(page);
        await expect(page.getByTestId('swap-open')).toHaveCount(0);
        await page.getByRole('button', { name: 'In Wartung' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('stock-swap')).toHaveCount(0);
        await page.getByTestId('stock-override').click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('Sollbestand unterschreiten?');
        await expect(dialog.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await dialog.getByRole('button', { name: 'Trotzdem buchen' }).click();

        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        expect(bookings).toHaveLength(2);
        expect(bookings[0]).not.toHaveProperty('confirmed_below_minimum');
        expect(bookings[1]).toMatchObject({ status: 'maintenance', confirmed_below_minimum: true });
    });
});
