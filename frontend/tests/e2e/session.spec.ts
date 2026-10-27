import { expect, HTTP_ERROR_CONSOLE, meBody, NOT_AUTHENTICATED, test } from './fixtures';

test.describe('Weiche „/“ und Wächter', () => {
    test.describe('ohne Sitzung', () => {
        test.use({
            api: { 'GET api/v1/auth/me': NOT_AUTHENTICATED },
            allowedConsoleError: HTTP_ERROR_CONSOLE,
        });

        test('„/“ führt zur Anmeldung', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        });

        test('geschützte Seite merkt sich die Rückkehradresse', async ({ page }) => {
            await page.goto('/firefighting/test/dashboard');
            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden\?weiter=\/dashboard$/);
        });

        test('„Zur Startseite“ behält den Basispfad', async ({ page }) => {
            await page.goto('/firefighting/test/gibt/es/nicht');
            await page.getByRole('link', { name: 'Zur Startseite' }).click();
            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        });
    });

    test.describe('als User', () => {
        test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('user') } } });

        test('„/“ führt zu „Suchen“', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Suchen' })).toBeVisible();
            await expect(page.getByTestId('account-name')).toHaveText('Anna');
        });

        test('Dashboard ist für User gesperrt', async ({ page }) => {
            await page.goto('/firefighting/test/dashboard');
            await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        });
    });

    test.describe('als Admin', () => {
        test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('admin') } } });

        test('„/“ führt zum Dashboard, auch im Handy-Format', async ({ page, apiCalls }) => {
            await page.goto('/firefighting/test/');
            await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
            expect(apiCalls.filter((path) => path === 'api/v1/auth/me')).toHaveLength(1);
        });
    });

    test.describe('als Super-Admin', () => {
        test.use({ api: { 'GET api/v1/auth/me': { status: 200, body: meBody('superadmin') } } });

        test('„/service/“ führt zum Dashboard', async ({ page }) => {
            await page.goto('/firefighting/service/');
            await expect(page).toHaveURL(/\/firefighting\/service\/dashboard$/);
            await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
            await expect(page.getByText('(Super-Admin)')).toBeVisible();
        });
    });

    test.describe('ohne Verbindung', () => {
        test.use({
            api: { 'GET api/v1/auth/me': 'abort' },
            allowedConsoleError: /internet|network|Failed to load|ERR_/i,
        });

        test('Anmeldeseite mit Hinweis', async ({ page }) => {
            await page.goto('/firefighting/test/dashboard');
            await expect(page).toHaveURL(/anmelden\?weiter=\/dashboard$/);
            await expect(page.getByTestId('login-notice')).toContainText('Keine Verbindung');
        });
    });
});

test.describe('Abmelden', () => {
    test.describe('erfolgreich', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('admin') },
                'POST api/v1/auth/logout': { status: 200, body: { logged_out: true } },
            },
        });

        test('führt zur Anmeldung mit Hinweis', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/dashboard');
            await page.getByRole('button', { name: 'Abmelden' }).click();
            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
            await expect(page.getByTestId('login-notice')).toHaveText('Sie wurden abgemeldet.');
            const logout = apiRequests.find((r) => r.path === 'api/v1/auth/logout');
            expect(logout?.body).toEqual({});
        });
    });

    test.describe('ohne Verbindung', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': { status: 200, body: meBody('user') },
                'POST api/v1/auth/logout': 'abort',
            },
            allowedConsoleError: /internet|network|Failed to load|ERR_/i,
        });

        test('bleibt angemeldet und sagt warum', async ({ page }) => {
            await page.goto('/firefighting/test/suchen');
            await page.getByRole('button', { name: 'Abmelden' }).click();
            await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
            await expect(page.getByTestId('error-code')).toHaveText('Code: network');
            await expect(page).toHaveURL(/\/suchen$/);
        });
    });
});
