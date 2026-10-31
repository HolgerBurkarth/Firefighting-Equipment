import type { Page } from '@playwright/test';
import {
    expect,
    meBody,
    NETWORK_ERROR_CONSOLE,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { deviceDetail, deviceJson, devicesEndpoint, LOCATIONS } from './support/devices';

const ME = { status: 200, body: meBody('user') } as const;
const RECENT_LOCATIONS_KEY = 'ff-recent-locations:test:7';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

/** M1 an HLF 20 / G1, einsatzbereit, Version 1. */
const M1 = deviceJson(1);

const LF10 = { id: 20, name: 'LF 10', path: 'LF 10' };

/** Antwort von POST devices/{id}/state wie DeviceStateHandler (übernommen). */
function applied(request: MockRequest, version = 2): MockResponse {
    const body = request.body as Record<string, unknown>;
    const status = typeof body['status'] === 'string' ? body['status'] : 'ready';
    const labels: Record<string, string> = {
        ready: 'einsatzbereit',
        maintenance: 'in Wartung',
        defective: 'defekt',
    };
    const device = deviceJson(1, {
        version,
        status,
        status_label: labels[status],
        ...(body['location_id'] === 20 && { location: LF10 }),
    });
    return {
        status: 200,
        body: {
            device,
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

function stateRequests(requests: readonly MockRequest[]): Record<string, unknown>[] {
    return requests
        .filter((r) => r.method === 'POST' && r.path === 'api/v1/devices/1/state')
        .map((r) => r.body as Record<string, unknown>);
}

/** Über „Suchen“ öffnen (wie im Alltag): Danach führt Speichern zurück. */
async function openFromSearch(page: Page): Promise<void> {
    await page.goto('/firefighting/test/suchen');
    await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('M1');
    await page.getByTestId('device-card').first().click();
    await expect(
        page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
    ).toBeVisible();
}

function statusButton(page: Page, label: string) {
    return page.getByRole('button', { name: label, exact: true });
}

const COMMON = {
    'GET api/v1/auth/me': ME,
    'GET api/v1/devices': devicesEndpoint([M1]),
    ...deviceDetail(M1),
    ...LOCATIONS,
};

test.describe('Umbuchen und Status', () => {
    test.use({ api: { ...COMMON, 'POST api/v1/devices/1/state': (r) => applied(r) } });

    test('zeigt Gerät, aktuellen Standort und Status', async ({ page }) => {
        await openFromSearch(page);
        await expect(page.getByText('Jetzt: HLF 20 / G1 · einsatzbereit')).toBeVisible();
        const choices = page.getByTestId('location-choice');
        await expect(choices).toHaveText([/HLF 20 \/ G1\s*aktuell/]);
        await expect(choices.first()).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByTestId('status-choice')).toHaveText([
            /✓\s*einsatzbereit/,
            /◐\s*in Wartung/,
            /✕\s*defekt/,
        ]);
        await expect(statusButton(page, 'einsatzbereit')).toHaveAttribute('aria-pressed', 'true');
        await expect(page.getByLabel('Kommentar (optional)')).toBeVisible();
    });

    test('„defekt“: Kommentar Pflicht (mindestens 5 Zeichen), dann grüne Bestätigung', async ({
        page,
        apiRequests,
    }) => {
        await openFromSearch(page);
        await statusButton(page, 'defekt').click();
        await expect(statusButton(page, 'defekt')).toHaveAttribute('aria-pressed', 'true');
        const comment = page.getByLabel('Was ist defekt?');
        await expect(comment).toBeVisible();
        await expect(
            page.getByText('Bitte kurz beschreiben, was nicht funktioniert.'),
        ).toBeVisible();

        await comment.fill('  kap  ');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('comment-error')).toHaveText(
            'Bitte beschreiben Sie den Defekt mit mindestens 5 Zeichen.',
        );
        await expect(comment).toHaveAttribute('aria-invalid', 'true');
        await comment.fill('Ven');
        await page.getByRole('button', { name: 'Speichern' }).click();
        expect(stateRequests(apiRequests)).toHaveLength(0);

        await comment.fill('Ventil\nundicht');
        await expect(page.getByTestId('comment-error')).toBeHidden();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByTestId('booking-notice')).toHaveText('✓ M1 → HLF 20 / G1 · defekt');
        await expect(page.getByTestId('booking-notice')).toHaveClass(/notice-success/);

        const [body] = stateRequests(apiRequests);
        expect(body).toEqual({
            version: 1,
            status: 'defective',
            comment: 'Ventil undicht',
            client_ref: expect.stringMatching(UUID_V4) as unknown,
        });
        // Zurück wie der Knopf des Browsers: „Suchen“ steht nicht zweimal in der Historie.
        await expect
            .poll(() => page.evaluate(() => window.history.state as unknown))
            .toMatchObject({ forward: '/geraete/1' });
        // Die Bestätigung verschwindet mit der nächsten Eingabe.
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('x');
        await expect(page.getByTestId('booking-notice')).toBeHidden();
    });

    test('Umbuchen auf einen zuletzt benutzten Standort; ohne gesperrte und unbekannte', async ({
        page,
        apiRequests,
    }) => {
        // 999 gibt es nicht (z. B. gesperrt), 20 = LF 10, 11 ist der aktuelle Standort.
        await page.addInitScript((key) => {
            window.localStorage.setItem(key, JSON.stringify([999, 20, 11]));
        }, RECENT_LOCATIONS_KEY);
        await openFromSearch(page);
        const choices = page.getByTestId('location-choice');
        await expect(choices).toHaveText([/HLF 20 \/ G1\s*aktuell/, /LF 10\s*zuletzt/]);
        await choices.filter({ hasText: 'LF 10' }).click();
        await expect(choices.filter({ hasText: 'LF 10' })).toHaveAttribute('aria-pressed', 'true');
        await expect(choices.first()).toHaveAttribute('aria-pressed', 'false');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText('✓ M1 → LF 10 · einsatzbereit');
        expect(stateRequests(apiRequests)).toEqual([
            { version: 1, location_id: 20, client_ref: expect.stringMatching(UUID_V4) as unknown },
        ]);
        // Neues Ziel vorn, höchstens 3.
        await expect
            .poll(() =>
                page.evaluate((key) => window.localStorage.getItem(key), RECENT_LOCATIONS_KEY),
            )
            .toBe('[20,999,11]');
    });

    test('nichts geändert: Hinweis, keine Anfrage', async ({ page, apiRequests }) => {
        await openFromSearch(page);
        await page.getByLabel('Kommentar (optional)').fill('nur ein Kommentar');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(
            page.getByText('Bitte wählen Sie einen anderen Standort oder Status.'),
        ).toBeVisible();
        // Erneutes Antippen des jetzigen Status ist keine Änderung.
        await statusButton(page, 'in Wartung').click();
        await statusButton(page, 'einsatzbereit').click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(
            page.getByText('Bitte wählen Sie einen anderen Standort oder Status.'),
        ).toBeVisible();
        expect(stateRequests(apiRequests)).toHaveLength(0);
        await expect(page).toHaveURL(/\/geraete\/1$/);
    });

    test('Bedienelemente mindestens 44 px, Handy-Breite ohne seitliches Scrollen', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto('/firefighting/test/geraete/1');
        await expect(page.getByTestId('status-choice')).toHaveCount(3);
        for (const element of [
            ...(await page.getByTestId('status-choice').all()),
            ...(await page.getByTestId('location-choice').all()),
            page.getByRole('button', { name: 'Speichern' }),
        ]) {
            const box = await element.boundingBox();
            expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
        }
        // Kein Element ragt über die Seite hinaus. Nicht über clientWidth: Das emulierte iPhone
        // (WebKit unter Windows) zieht davon eine Scrollleiste ab, echte iPhones legen sie über den
        // Inhalt.
        const overflow = await page.evaluate(() => {
            const width = document.documentElement.getBoundingClientRect().right;
            return [...document.querySelectorAll('body *')]
                .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                .map((element) => `${element.tagName}.${element.className}`);
        });
        expect(overflow).toEqual([]);
        // „Speichern“ bleibt unten im Fenster.
        const save = await page.getByRole('button', { name: 'Speichern' }).boundingBox();
        expect((save?.y ?? 0) + (save?.height ?? 0)).toBeLessThanOrEqual(640);
    });
});

test.describe('Schon defekt', () => {
    test.use({
        api: {
            ...COMMON,
            ...deviceDetail({ ...M1, status: 'defective', status_label: 'defekt' }),
            'POST api/v1/devices/1/state': (r) => applied(r),
        },
    });

    test('umbuchen braucht keinen Kommentar', async ({ page, apiRequests }) => {
        await page.addInitScript((key) => {
            window.localStorage.setItem(key, JSON.stringify([20]));
        }, RECENT_LOCATIONS_KEY);
        await page.goto('/firefighting/test/geraete/1');
        await expect(page.getByText('Jetzt: HLF 20 / G1 · defekt')).toBeVisible();
        await expect(statusButton(page, 'defekt')).toHaveAttribute('aria-pressed', 'true');
        await page.getByTestId('location-choice').filter({ hasText: 'LF 10' }).click();
        await expect(page.getByLabel('Kommentar (optional)')).toBeVisible();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
        expect(stateRequests(apiRequests)).toEqual([
            { version: 1, location_id: 20, client_ref: expect.stringMatching(UUID_V4) as unknown },
        ]);
    });
});

test.describe('Konflikt', () => {
    let calls = 0;
    test.use({
        api: {
            ...COMMON,
            'POST api/v1/devices/1/state': (request): MockResponse => {
                calls += 1;
                if (calls > 1) {
                    return applied(request, 3);
                }
                return {
                    status: 409,
                    body: {
                        error: 'version_conflict',
                        message: 'Das Gerät wurde inzwischen geändert.',
                        details: {
                            device: deviceJson(1, {
                                version: 2,
                                status: 'maintenance',
                                status_label: 'in Wartung',
                            }),
                        },
                    },
                };
            },
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('zeigt den neuen Stand, behält die eigene Änderung, neue client_ref', async ({
        page,
        apiRequests,
    }) => {
        await page.addInitScript((key) => {
            window.localStorage.setItem(key, JSON.stringify([20]));
        }, RECENT_LOCATIONS_KEY);
        await openFromSearch(page);
        await page.getByTestId('location-choice').filter({ hasText: 'LF 10' }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('booking-conflict')).toContainText(
            'Das Gerät wurde inzwischen geändert.',
        );
        await expect(page.getByText('Jetzt: HLF 20 / G1 · in Wartung')).toBeVisible();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        // Eigene Wahl bleibt, der nicht geänderte Status folgt dem neuen Stand.
        await expect(
            page.getByTestId('location-choice').filter({ hasText: 'LF 10' }),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect(statusButton(page, 'in Wartung')).toHaveAttribute('aria-pressed', 'true');

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText('✓ M1 → LF 10 · einsatzbereit');
        const [first, second] = stateRequests(apiRequests);
        expect(first).toMatchObject({ version: 1, location_id: 20 });
        expect(second).toMatchObject({ version: 2, location_id: 20 });
        expect(second).not.toHaveProperty('status');
        expect(second?.['client_ref']).not.toBe(first?.['client_ref']);
    });
});

test.describe('Keine Verbindung beim Speichern', () => {
    let calls = 0;
    test.use({
        api: {
            ...COMMON,
            'POST api/v1/devices/1/state': (request): MockResponse => {
                calls += 1;
                return calls === 1 ? 'abort' : applied(request);
            },
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('Eingaben bleiben, erneutes Senden mit derselben client_ref', async ({
        page,
        apiRequests,
    }) => {
        await openFromSearch(page);
        await statusButton(page, 'in Wartung').click();
        await page.getByLabel('Kommentar (optional)').fill('zur Prüfung');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('alert')).toContainText('Keine Verbindung zum Server');
        await expect(page.getByLabel('Kommentar (optional)')).toHaveValue('zur Prüfung');
        await expect(statusButton(page, 'in Wartung')).toHaveAttribute('aria-pressed', 'true');

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('booking-notice')).toHaveText(
            '✓ M1 → HLF 20 / G1 · in Wartung',
        );
        const [first, second] = stateRequests(apiRequests);
        expect(second).toEqual(first);
    });
});

test.describe('Fehler des Servers', () => {
    test.use({
        api: {
            ...COMMON,
            'POST api/v1/devices/1/state': {
                status: 409,
                body: {
                    error: 'rule_violation',
                    message: 'Der Standort „LF 10“ ist gesperrt.',
                },
            },
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test('zeigt die Meldung, bleibt auf der Seite', async ({ page }) => {
        await openFromSearch(page);
        await statusButton(page, 'defekt').click();
        await page.getByLabel('Was ist defekt?').fill('Maske gerissen');
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('alert')).toContainText('Der Standort „LF 10“ ist gesperrt.');
        await expect(page.getByTestId('error-code')).toHaveText('Code: rule_violation');
        await expect(page).toHaveURL(/\/geraete\/1$/);
    });
});

test.describe('Besondere Geräte', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...deviceDetail(deviceJson(4, { status: 'retired', status_label: 'ausgesondert' })),
            'GET api/v1/devices/5': {
                status: 404,
                body: { error: 'not_found', message: 'Nicht gefunden.' },
            },
            ...LOCATIONS,
        },
        allowedConsoleError: NETWORK_ERROR_CONSOLE,
    });

    test('ausgesondert: kein Formular', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/4');
        await expect(page.getByTestId('device-retired')).toHaveText(
            'Dieses Gerät ist ausgesondert und kann nicht umgebucht werden.',
        );
        await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);
    });

    test('unbekannt: Hinweis', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/5');
        await expect(page.getByText('Dieses Gerät gibt es nicht (mehr).')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Speichern' })).toHaveCount(0);
    });
});
