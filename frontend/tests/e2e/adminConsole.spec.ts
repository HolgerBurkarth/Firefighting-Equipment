import type { Locator, Page } from '@playwright/test';
import { DATABASE_UNAVAILABLE, expect, HTTP_ERROR_CONSOLE, meBody, test } from './fixtures';
import { adminOverview, summaryJson, tenantEventJson, userJson } from './support/admin';
import { deviceJson } from './support/devices';

/** Höhe bzw. Breite in px, gerundet (WebKit misst z. B. 43,99 statt 44). */
async function size(locator: Locator): Promise<{ width: number; height: number }> {
    const box = await locator.boundingBox();
    return { width: Math.round(box?.width ?? 0), height: Math.round(box?.height ?? 0) };
}

const ME = { status: 200, body: meBody('admin', 'Thomas Keller') } as const;

const OVERVIEW = adminOverview({
    summary: summaryJson({
        ready: 42,
        maintenance: 1,
        defective: 2,
        overdue: 2,
        dueSoon: 4,
        openNotices: 1,
    }),
    pending: [userJson(21), userJson(22)],
    notReady: [
        deviceJson(4, {
            identifier: 'PA 4',
            device_type: { id: 2, name: 'Atemschutzgerät' },
            location: { id: 30, name: 'Atemschutzwerkstatt', path: 'Atemschutzwerkstatt' },
            status: 'defective',
            status_label: 'defekt',
        }),
        deviceJson(10, { status: 'maintenance', status_label: 'in Wartung' }),
    ],
    notReadyTotal: 3,
    events: [
        tenantEventJson(90, { comment: 'Nach der Übung' }),
        tenantEventJson(89, {
            type_label: 'Umbuchung und Statusänderung nicht übernommen',
            applied: false,
            not_applied_reason: 'older',
            not_applied_label: 'Neuerer Stand vorhanden',
            location: { from: null, to: { id: 11, name: 'G1', path: 'HLF 20 / G1' } },
            status: { from: null, from_label: null, to: 'defective', to_label: 'defekt' },
            device: { id: 7, identifier: 'PA 3', device_type: { id: 2, name: 'Atemschutzgerät' } },
        }),
    ],
});

/** Einträge des Menüs (ADMIN_MENU in src/router/adminMenu.ts), ohne Abmelden. */
const MENU_LABELS = ['Dashboard', 'Suchen', 'Geräte', 'Standorte', 'Gerätetypen'];

function menu(page: Page): Locator {
    return page.getByRole('navigation', { name: 'Hauptmenü' });
}

test.describe('Admin-Konsole', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'POST api/v1/auth/logout': { status: 200, body: { logged_out: true } },
            ...OVERVIEW,
        },
    });

    test('PC: Menü links mit Text, Name als Text und Abmelden', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        const nav = menu(page);
        await expect(nav).toBeVisible();
        await expect(nav.getByTestId('menu-tenant')).toHaveText('Feuerwehr Test');
        await expect(nav.getByRole('link')).toHaveText(MENU_LABELS);
        await expect(nav.getByRole('link', { name: 'Dashboard' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await expect(nav.getByTestId('account-name')).toHaveText('Thomas Keller');
        await expect(nav.getByRole('button', { name: 'Abmelden' })).toBeVisible();
        expect((await size(nav)).width).toBeGreaterThan(200);
        for (const link of await nav.getByRole('link').all()) {
            expect((await size(link)).height).toBeGreaterThanOrEqual(44);
        }
        // Keine Kopfleiste mit ☰ und keine Leiste unten.
        await expect(page.getByRole('button', { name: 'Menü öffnen' })).toBeHidden();
        await expect(page.locator('.tab-bar')).toHaveCount(0);

        await nav.getByRole('link', { name: 'Suchen' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Suchen' })).toBeVisible();
    });

    test('Tablet: Menü eingeklappt auf Symbole, Namen für Bildschirmleser', async ({ page }) => {
        await page.setViewportSize({ width: 900, height: 1000 });
        await page.goto('/firefighting/test/dashboard');
        const nav = menu(page);
        await expect(nav).toBeVisible();
        expect((await size(nav)).width).toBeLessThanOrEqual(80);
        await expect(nav.getByRole('link')).toHaveText(MENU_LABELS);
        const search = nav.getByRole('link', { name: 'Suchen' });
        await expect(search).toHaveAttribute('title', 'Suchen');
        // Nur das Symbol: Der Text ist unsichtbar (1 px), der Name bleibt für Bildschirmleser.
        expect(await size(search)).toEqual({ width: 44, height: 44 });
        expect((await size(nav.getByText('Suchen', { exact: true }))).width).toBeLessThanOrEqual(1);
        await expect(nav.getByRole('button', { name: 'Abmelden' })).toBeVisible();
        await search.click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
    });

    test('Handy: Kopfleiste mit ☰, Schublade mit dem Menü', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
        await expect(menu(page)).toHaveCount(0);
        await expect(page.getByTestId('app-title')).toHaveText('Feuerwehr Test');
        const open = page.getByRole('button', { name: 'Menü öffnen' });
        expect(await size(open)).toEqual({ width: 44, height: 44 });

        // Escape schließt, der Fokus geht zurück auf ☰.
        await open.click();
        const drawer = page.getByRole('dialog', { name: 'Menü' });
        await expect(drawer).toBeVisible();
        await expect(menu(page).getByRole('link')).toHaveText(MENU_LABELS);
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
        await expect(open).toBeFocused();

        // „Menü schließen“ schließt ebenso.
        await open.click();
        await drawer.getByRole('button', { name: 'Menü schließen' }).click();
        await expect(drawer).toBeHidden();

        // Ein Seitenwechsel schließt die Schublade.
        await open.click();
        await menu(page).getByRole('link', { name: 'Suchen' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(drawer).toBeHidden();
        await expect(page.getByRole('heading', { level: 1, name: 'Suchen' })).toBeVisible();
    });

    test('Handy: Abmelden aus der Schublade', async ({ page, apiRequests }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/test/dashboard');
        await page.getByRole('button', { name: 'Menü öffnen' }).click();
        await page
            .getByRole('dialog', { name: 'Menü' })
            .getByRole('button', { name: 'Abmelden' })
            .click();
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        await expect(page.getByTestId('login-notice')).toHaveText('Sie wurden abgemeldet.');
        await expect(page.getByRole('dialog')).toHaveCount(0);
        expect(apiRequests.some((r) => r.path === 'api/v1/auth/logout')).toBe(true);
    });
});

test.describe('Dashboard', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...OVERVIEW } });

    test.beforeEach(async ({ page }) => {
        // Ortszeit fest (heute = 30.10.2026), damit „heute 08:12“ stimmt.
        await page.clock.setFixedTime(new Date('2026-10-30T12:00:00+01:00'));
    });

    test('Kacheln aus device-summary und den Freischaltungen', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByTestId('tile-readiness')).toContainText('42 von 45');
        await expect(page.getByTestId('tile-readiness')).toContainText('Geräte einsatzbereit');
        await expect(
            page.getByRole('progressbar', { name: 'Anteil einsatzbereit' }),
        ).toHaveAttribute('value', '93');
        await expect(page.getByTestId('tile-not-ready')).toContainText('3');
        await expect(page.getByTestId('tile-not-ready')).toContainText('2 defekt · 1 in Wartung');
        await expect(page.getByTestId('tile-inspection')).toContainText('2 überfällig');
        await expect(page.getByTestId('tile-inspection')).toContainText(
            '4 in den nächsten 30 Tagen',
        );
        await expect(page.getByTestId('tile-open')).toContainText('3');
        await expect(page.getByTestId('tile-open')).toContainText('1 Hinweis · 2 Freischaltungen');
        await expect(page.getByTestId('dashboard-time')).toHaveText('Stand 12:00');

        const users = apiRequests.find((r) => r.path === 'api/v1/users');
        expect(new URL(users?.url ?? '').searchParams.get('status')).toBe('pending');
        const devices = apiRequests.find((r) => r.path === 'api/v1/devices');
        const params = new URL(devices?.url ?? '').searchParams;
        expect([
            params.get('status'),
            params.get('sort'),
            params.get('dir'),
            params.get('limit'),
        ]).toEqual(['maintenance,defective', 'status', 'desc', '10']);
        const events = apiRequests.find((r) => r.path === 'api/v1/device-events');
        expect(new URL(events?.url ?? '').searchParams.get('limit')).toBe('8');
    });

    test('Mail-Links auf Benutzer und Hinweis führen vorerst auf das Dashboard', async ({
        page,
    }) => {
        // AppUrls::users und AppUrls::notice; die Seiten folgen in den Schritten 8 und 12.
        for (const path of ['benutzer', 'protokoll/7']) {
            await page.goto(`/firefighting/test/${path}`);
            await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
            await expect(page.getByTestId('tile-open')).toContainText(
                '1 Hinweis · 2 Freischaltungen',
            );
        }
    });

    test('Liste „Nicht einsatzbereit“ öffnet das Gerät', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        const list = page.getByTestId('not-ready-list');
        await expect(list.getByRole('link')).toHaveText([
            /PA 4\s*Atemschutzgerät · Atemschutzwerkstatt\s*✕\s*defekt/,
            /M10\s*Atemschutzmaske · HLF 20 \/ G1\s*◐\s*in Wartung/,
        ]);
        await expect(page.getByTestId('not-ready-more')).toHaveText('… und 1 weitere');
        await expect(list.getByRole('link', { name: /PA 4/ })).toHaveAttribute(
            'href',
            '/firefighting/test/geraete/4/details',
        );
    });

    test('Letzte Aktivitäten mit Wer, Wann und Von/Nach', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        const items = page.getByTestId('activity-list').getByRole('listitem');
        await expect(items).toHaveCount(2);
        await expect(items.nth(0)).toContainText('heute 08:12');
        await expect(items.nth(0)).toContainText('M5 Umgebucht · J. Müller');
        await expect(items.nth(0)).toContainText('Standort: Lager → HLF 20 / G1');
        await expect(items.nth(0)).toContainText('„Nach der Übung“');
        await expect(items.nth(0).getByRole('link', { name: 'M5' })).toHaveAttribute(
            'href',
            '/firefighting/test/geraete/5/details',
        );
        // Nicht übernommene Offline-Buchung: das Gewünschte und der Grund.
        await expect(items.nth(1)).toContainText(
            'PA 3 Umbuchung und Statusänderung nicht übernommen · J. Müller',
        );
        await expect(items.nth(1)).toContainText(
            'Standort (gewünscht): HLF 20 / G1 · Status (gewünscht): defekt · Grund: Neuerer Stand vorhanden',
        );
    });

    test('Kachel „Prüffristen“ führt zu den Prüffristen', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await page.getByTestId('tile-inspection').click();
        await expect(page).toHaveURL(/\/firefighting\/test\/prueffristen$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Prüffristen' })).toBeVisible();
    });
});

test.describe('Dashboard ohne Daten', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...adminOverview() } });

    test('leere Listen mit Hinweis', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByTestId('tile-readiness')).toContainText('0 von 0');
        await expect(page.getByTestId('tile-readiness')).toContainText('Noch keine Geräte erfasst');
        await expect(page.getByTestId('tile-open')).toContainText('Nichts zu erledigen');
        await expect(page.getByText('Alle Geräte sind einsatzbereit.')).toBeVisible();
        await expect(page.getByText('Noch keine Einträge.')).toBeVisible();
    });
});

test.describe('Dashboard mit Störung', () => {
    let calls = 0;
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...OVERVIEW,
            'GET api/v1/device-summary': () => {
                calls += 1;
                return calls === 1
                    ? DATABASE_UNAVAILABLE
                    : { status: 200, body: summaryJson({ ready: 5 }) };
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('zeigt den Fehler und lädt per Knopf erneut', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(page.getByTestId('tile-readiness')).toContainText('5 von 5');
        await expect(page.getByRole('alert')).toHaveCount(0);
    });
});

test.describe('Dunkel', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...OVERVIEW }, colorScheme: 'dark' });

    test('Menü und Kacheln auf dunkler Fläche', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page.locator('.admin-sidebar')).toHaveCSS(
            'background-color',
            'rgb(27, 34, 43)',
        );
        await expect(page.getByTestId('tile-readiness')).toHaveCSS(
            'background-color',
            'rgb(27, 34, 43)',
        );
    });
});
