import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockResponse,
} from './fixtures';
import {
    deviceDetail,
    deviceJson,
    devicesEndpoint,
    LOCATIONS,
    manyDevices,
    NO_DEVICES,
} from './support/devices';

/** 29.10.2026 14:30 in Berlin (Zeitzone in playwright.config.ts). */
const NOW = new Date('2026-10-29T13:30:00Z');
const ME = { status: 200, body: meBody('user') } as const;

const DEVICES = [
    deviceJson(1, { identifier: 'M1', next_inspection_due: '2026-10-28' }),
    deviceJson(2, { identifier: 'M2', next_inspection_due: null }),
    deviceJson(3, {
        identifier: 'HRT 5',
        device_type: { id: 3, name: 'Funkgerät' },
        location: { id: 20, name: 'LF 10', path: 'LF 10' },
        status: 'defective',
        status_label: 'defekt',
        next_inspection_due: '2026-10-01',
    }),
    deviceJson(4, { identifier: 'M4', next_inspection_due: '2026-10-29' }),
    deviceJson(5, { identifier: 'M5', next_inspection_due: '2026-11-28' }),
    deviceJson(6, { identifier: 'M6', next_inspection_due: '2026-11-29' }),
    deviceJson(7, { identifier: 'M7', next_inspection_due: '2026-10-30' }),
];

test.describe('Prüffristen (lokal)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(DEVICES),
            ...deviceDetail(DEVICES[0] ?? {}),
            ...deviceDetail(DEVICES[2] ?? {}),
            ...LOCATIONS,
            'POST api/v1/devices/1/state': {
                status: 200,
                body: {
                    device: deviceJson(1, {
                        version: 2,
                        status: 'maintenance',
                        status_label: 'in Wartung',
                        next_inspection_due: '2026-10-28',
                    }),
                    result: {
                        outcome: 'applied',
                        outcome_label: 'übernommen',
                        reason: null,
                        reason_label: null,
                        message: 'Übernommen.',
                        stale_confirmed: false,
                        client_ref: '00000000-0000-4000-8000-000000000001',
                        replayed: false,
                        notice_id: null,
                    },
                },
            },
        },
    });

    test.beforeEach(async ({ page }) => {
        await page.clock.setFixedTime(NOW);
    });

    test('überfällig und in 30 Tagen fällig; Antippen öffnet das Umbuchen', async ({ page }) => {
        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByRole('heading', { level: 1, name: 'Prüffristen' })).toBeVisible();
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link', { name: 'Prüffristen' })).toHaveAttribute(
            'aria-current',
            'page',
        );

        await expect(page.getByTestId('overdue-count')).toHaveText('2 Geräte');
        const overdue = page.getByTestId('overdue-list').getByTestId('device-card');
        await expect(overdue.locator('.device-identifier')).toHaveText(['HRT 5', 'M1']);
        await expect(overdue.getByTestId('inspection-due')).toHaveText([
            'Prüfung: 01.10.2026 · seit 28 Tagen überfällig',
            'Prüfung: 28.10.2026 · seit 1 Tag überfällig',
        ]);
        await expect(overdue.first()).toContainText('Funkgerät');
        await expect(overdue.first()).toContainText('LF 10');
        await expect(overdue.first()).toContainText('✕ defekt');

        await expect(
            page.getByRole('heading', { name: /In den nächsten 30 Tagen fällig/ }),
        ).toBeVisible();
        await expect(page.getByTestId('due-soon-count')).toHaveText('3 Geräte');
        const soon = page.getByTestId('due-soon-list').getByTestId('device-card');
        await expect(soon.getByTestId('inspection-due')).toHaveText([
            'Prüfung: 29.10.2026 · heute fällig',
            'Prüfung: 30.10.2026 · morgen fällig',
            'Prüfung: 28.11.2026 · in 30 Tagen fällig',
        ]);

        // Überfällig in der Statusfarbe „defekt“, bald fällig in „Wartung“ (nicht nur die Farbe:
        // der Text sagt es auch).
        const color = (name: string) =>
            page.evaluate(
                (token) => getComputedStyle(document.documentElement).getPropertyValue(token),
                name,
            );
        const rgb = (hex: string) =>
            `rgb(${[1, 3, 5].map((i) => parseInt(hex.trim().slice(i, i + 2), 16)).join(', ')})`;
        await expect(overdue.first().getByTestId('inspection-due')).toHaveCSS(
            'color',
            rgb(await color('--status-defective-fg')),
        );
        await expect(soon.first().getByTestId('inspection-due')).toHaveCSS(
            'color',
            rgb(await color('--status-maintenance-fg')),
        );

        await overdue.first().click();
        await expect(page).toHaveURL(/\/geraete\/3$/);
        await expect(
            page.getByRole('heading', { level: 1, name: 'HRT 5 · Funkgerät' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Zurück', exact: true }).click();
        await expect(page).toHaveURL(/\/prueffristen$/);
    });

    test('nach dem Speichern zurück zu den Prüffristen mit Bestätigung', async ({
        page,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/prueffristen');
        await page
            .getByTestId('overdue-list')
            .getByTestId('device-card')
            .filter({ hasText: 'M1' })
            .click();
        await expect(
            page.getByRole('heading', { level: 1, name: 'M1 · Atemschutzmaske' }),
        ).toBeVisible();
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page).toHaveURL(/\/firefighting\/test\/prueffristen$/);
        await expect(page.getByTestId('booking-notice')).toHaveText(
            '✓ M1 → HLF 20 / G1 · in Wartung',
        );
        await expect(page.getByTestId('booking-notice')).toHaveClass(/notice-success/);
        expect(
            apiRequests.filter((r) => r.method === 'POST' && r.path === 'api/v1/devices/1/state'),
        ).toHaveLength(1);
        // Zurück wie der Knopf des Browsers: „Prüffristen“ steht nicht zweimal in der Historie.
        expect(await page.evaluate(() => window.history.state as unknown)).toMatchObject({
            forward: '/geraete/1',
        });

        // Die Bestätigung gilt nur für diesen Besuch, auch „Suchen“ zeigt sie nicht mehr.
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await nav.getByRole('link', { name: 'Suchen' }).click();
        await expect(page.getByRole('searchbox', { name: 'Gerät suchen' })).toBeVisible();
        await expect(page.getByTestId('booking-notice')).toBeHidden();
        await nav.getByRole('link', { name: 'Prüffristen' }).click();
        await expect(page.getByTestId('overdue-count')).toBeVisible();
        await expect(page.getByTestId('booking-notice')).toBeHidden();
    });

    test('Handy-Breite: kein seitliches Überstehen', async ({ page }) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByTestId('overdue-list').getByTestId('device-card')).toHaveCount(2);
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

test.describe('Prüffristen ohne fällige Geräte', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...NO_DEVICES } });

    test('Hinweise statt Listen', async ({ page }) => {
        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByTestId('overdue-empty')).toHaveText('Keine Prüfung überfällig.');
        await expect(page.getByTestId('due-soon-empty')).toHaveText(
            'In den nächsten 30 Tagen ist keine Prüfung fällig.',
        );
        await expect(page.getByTestId('overdue-count')).toHaveText('0 Geräte');
    });
});

test.describe('Prüffristen auf dem Server (mehr als 2000 Geräte)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(manyDevices(200), () => [], 2001),
            'GET api/v1/inspection-due': {
                status: 200,
                body: {
                    today: '2026-10-29',
                    days: 30,
                    until: '2026-11-28',
                    overdue: {
                        devices: [
                            {
                                ...deviceJson(4711, {
                                    identifier: 'ABC-1',
                                    next_inspection_due: '2026-10-19',
                                }),
                                days_overdue: 10,
                            },
                        ],
                        total: 250,
                    },
                    due_soon: { devices: [], total: 0 },
                },
            },
        },
    });

    test('Abschnitte vom Server, Hinweis bei mehr Geräten', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByTestId('overdue-count')).toHaveText('250 Geräte');
        await expect(page.getByTestId('overdue-list').getByTestId('inspection-due')).toHaveText([
            'Prüfung: 19.10.2026 · seit 10 Tagen überfällig',
        ]);
        await expect(page.getByTestId('overdue-more')).toHaveText('Angezeigt werden die ersten 1.');
        await expect(page.getByTestId('due-soon-empty')).toBeVisible();
        const query = apiRequests.find((r) => r.path === 'api/v1/inspection-due');
        const params = new URL(query?.url ?? 'http://x/').searchParams;
        expect([params.get('days'), params.get('limit')]).toEqual(['30', '200']);
    });
});

test.describe('Prüffristen: Liste nicht ladbar', () => {
    let calls = 0;
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': (request): MockResponse => {
                calls += 1;
                return calls === 1 ? DATABASE_UNAVAILABLE : devicesEndpoint(DEVICES)(request);
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('Fehler mit erneutem Versuch', async ({ page }) => {
        await page.clock.setFixedTime(NOW);
        await page.goto('/firefighting/test/prueffristen');
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(page.getByTestId('overdue-count')).toHaveText('2 Geräte');
    });
});
