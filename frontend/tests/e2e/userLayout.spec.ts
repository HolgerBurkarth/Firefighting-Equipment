import type { Locator } from '@playwright/test';
import { expect, meBody, test } from './fixtures';

/** Höhe in px, gerundet: WebKit misst wegen seiner Skalierung z. B. 43,99 statt 44. */
async function height(locator: Locator): Promise<number> {
    return Math.round((await locator.boundingBox())?.height ?? 0);
}

/** Flächenfarbe --surface aus tokens.css (hell bzw. dunkel). */
const SURFACE_LIGHT = 'rgb(255, 255, 255)';
const SURFACE_DARK = 'rgb(27, 34, 43)';

test.describe('Handy-Seiten als User', () => {
    test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('user', 'Jonas') } } });

    test('Kopfleiste mit Begrüßung und Leiste unten', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await expect(page.getByTestId('app-title')).toHaveText('Feuerwehr Test');
        await expect(page.getByTestId('app-subtitle')).toHaveText('Hallo Jonas');
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link')).toHaveText(['Suchen', 'Prüffristen', 'Profil']);
        await expect(nav.getByRole('link', { name: 'Suchen' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        for (const link of await nav.getByRole('link').all()) {
            expect(await height(link)).toBeGreaterThanOrEqual(44);
        }
        // Die Leiste steht unten im Fenster, auch bei wenig Inhalt (innerHeight: Desktop-Safari
        // in Playwright ist niedriger als viewportSize()).
        const bottom = await nav.evaluate((element) => ({
            nav: Math.round(element.getBoundingClientRect().bottom),
            window: window.innerHeight,
        }));
        expect(bottom.nav).toBe(bottom.window);
    });

    test('wechselt zwischen Suchen, Prüffristen und Profil', async ({ page, apiCalls }) => {
        await page.goto('/firefighting/test/suchen');
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });

        await nav.getByRole('link', { name: 'Prüffristen' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/prueffristen$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Prüffristen' })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Prüffristen' })).toHaveAttribute(
            'aria-current',
            'page',
        );

        await nav.getByRole('link', { name: 'Profil' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/profil$/);
        await expect(page.getByTestId('account-name')).toHaveText('Jonas');
        await expect(page.getByRole('button', { name: 'Abmelden' })).toBeVisible();
        // auth/me nur einmal je Seitenaufruf.
        expect(apiCalls.filter((path) => path === 'api/v1/auth/me')).toHaveLength(1);
    });

    test('Geräteseite bildschirmfüllend, Zurück ohne vorige Seite zu „Suchen“', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/geraete/12');
        await expect(page.getByRole('heading', { level: 1, name: 'Gerät #12' })).toBeVisible();
        await expect(page.getByTestId('app-title')).toHaveCount(0);
        await expect(page.getByRole('navigation', { name: 'Hauptmenü' })).toHaveCount(0);
        const back = page.getByRole('button', { name: 'Zurück' });
        expect(await height(back)).toBeGreaterThanOrEqual(44);
        await back.click();
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
    });

    test('Gerät mit ungültiger Nummer → „Seite nicht gefunden“', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/012');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });

    test('Dashboard bleibt für User gesperrt, ohne Eintrag in der Leiste', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Dashboard' }),
        ).toHaveCount(0);
    });
});

test.describe('Handy-Seiten als Admin', () => {
    test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('admin') } } });

    test('öffnet alle Handy-Seiten, Leiste mit Dashboard', async ({ page }) => {
        await page.goto('/firefighting/test/suchen');
        await expect(page.getByRole('heading', { level: 1, name: 'Suchen' })).toBeVisible();
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link')).toHaveText([
            'Dashboard',
            'Suchen',
            'Prüffristen',
            'Profil',
        ]);
        await nav.getByRole('link', { name: 'Dashboard' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
        // Das Dashboard hat die Darstellung der Admin-Konsole, ohne Leiste unten.
        await expect(page.getByTestId('app-subtitle')).toHaveText('Geräteverwaltung');
        await expect(page.getByRole('navigation', { name: 'Hauptmenü' })).toHaveCount(0);
    });

    test('Geräteseite öffnen', async ({ page }) => {
        await page.goto('/firefighting/test/geraete/5');
        await expect(page.getByRole('heading', { level: 1, name: 'Gerät #5' })).toBeVisible();
    });
});

test.describe('Hell und dunkel', () => {
    test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('user') } } });

    for (const [label, scheme, stored, surface] of [
        ['System hell', 'light', 'system', SURFACE_LIGHT],
        ['System dunkel', 'dark', 'system', SURFACE_DARK],
        ['erzwungen dunkel', 'light', 'dark', SURFACE_DARK],
        ['erzwungen hell', 'dark', 'light', SURFACE_LIGHT],
    ] as const) {
        test(label, async ({ page }) => {
            await page.emulateMedia({ colorScheme: scheme });
            // Gespeicherter Anzeigemodus wie in src/runtime/theme.ts ("system" = wie das Gerät).
            await page.addInitScript((mode) => {
                window.localStorage.setItem('ff-theme', mode);
            }, stored);
            await page.goto('/firefighting/test/suchen');
            await expect(page.getByRole('navigation', { name: 'Hauptmenü' })).toHaveCSS(
                'background-color',
                surface,
            );
            await page.goto('/firefighting/test/geraete/12');
            await expect(page.locator('header')).toHaveCSS('background-color', surface);
        });
    }
});
