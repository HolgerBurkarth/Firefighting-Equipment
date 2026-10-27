import { expect, TEST_TENANT, test } from './fixtures';

test.describe('Grundgerüst', () => {
    test('Startseite einer Stadt mit Name und Basispfad', async ({ page, apiCalls }) => {
        await page.goto('/firefighting/test/');
        await expect(page.getByRole('heading', { level: 1, name: 'Startseite' })).toBeVisible();
        await expect(page.getByTestId('app-title')).toHaveText('Feuerwehr Test');
        await expect(page).toHaveTitle('Feuerwehr Test – Geräteverwaltung');
        expect(await page.evaluate(() => document.baseURI)).toMatch(/\/firefighting\/test\/$/);
        expect(apiCalls).toContain('api/v1/tenant');
    });

    test('Deep-Link auf die Anmeldeseite', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await expect(page.getByLabel('E-Mail-Adresse')).toBeVisible();
        await expect(page.getByLabel('Passwort')).toBeVisible();
        const button = page.getByRole('button', { name: 'Anmelden' });
        await expect(button).toBeDisabled();
        const box = await button.boundingBox();
        expect(box?.height).toBeGreaterThanOrEqual(44);
    });

    test('Navigation innerhalb der App behält den Basispfad', async ({ page }) => {
        await page.goto('/firefighting/test/');
        await page.getByRole('link', { name: 'Zur Anmeldung' }).click();
        await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
    });

    test('Unbekannte Adresse zeigt "Seite nicht gefunden"', async ({ page }) => {
        await page.goto('/firefighting/test/gibt/es/nicht');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });

    test('Bereich des Super-Admins lädt keine Stadt', async ({ page, apiCalls }) => {
        await page.goto('/firefighting/service/');
        await expect(page.getByTestId('app-title')).toHaveText('Super-Admin');
        expect(apiCalls).not.toContain('api/v1/tenant');
    });

    test('Schrift Source Sans 3 wird geladen', async ({ page }) => {
        await page.goto('/firefighting/test/');
        await expect(page.getByTestId('app-title')).toHaveText('Feuerwehr Test');
        const loaded = await page.evaluate(async () => {
            await document.fonts.ready;
            return document.fonts.check('700 16px "Source Sans 3"');
        });
        expect(loaded).toBe(true);
    });
});

test.describe('Stadt unbekannt', () => {
    test.use({
        tenant: { status: 404, body: { error: 'tenant_not_found', message: 'Unbekannt.' } },
        // Meldung des Browsers zur 404-Antwort (Chromium, Firefox, WebKit unterschiedlich).
        allowedConsoleError: /404|Not Found/i,
    });

    test('zeigt einen verständlichen Hinweis', async ({ page }) => {
        await page.goto('/firefighting/gibtsnicht/');
        await expect(page.getByRole('alert')).toContainText(
            'Diese Feuerwehr gibt es unter dieser Adresse nicht.',
        );
        await expect(page.getByRole('alert')).toContainText('tenant_not_found');
    });
});

test.describe('Stadtfarbe', () => {
    test('Standard ist Feuerwehrrot', async ({ page }) => {
        await page.goto('/firefighting/test/');
        await expect(page.getByTestId('app-title')).toHaveText('Feuerwehr Test');
        await expect(page.locator('header')).toHaveCSS('background-color', 'rgb(183, 28, 28)');
    });

    test.describe('eigene Farbe', () => {
        test.use({
            tenant: { status: 200, body: { ...TEST_TENANT.body, accent_color: '#1d3557' } },
        });

        test('färbt Kopfleiste und theme-color', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page.locator('header')).toHaveCSS('background-color', 'rgb(29, 53, 87)');
            await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute(
                'content',
                '#1d3557',
            );
        });
    });

    test.describe('mit Logo', () => {
        const logoUrls = {
            '512': '/firefighting/test/api/v1/tenant/logo/512?v=abc',
            '192': '/firefighting/test/api/v1/tenant/logo/192?v=abc',
            '180': '/firefighting/test/api/v1/tenant/logo/180?v=abc',
        };
        test.use({
            tenant: {
                status: 200,
                body: { ...TEST_TENANT.body, logo_url: logoUrls['512'], logo_urls: logoUrls },
            },
        });

        test('setzt App-Symbol und apple-touch-icon', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute(
                'href',
                logoUrls['180'],
            );
            await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', logoUrls['192']);
        });
    });
});

test.describe('Anzeigemodus', () => {
    test.describe('hell (System)', () => {
        test.use({ colorScheme: 'light' });

        test('helle Farben', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(243, 244, 246)');
        });

        test('gespeicherte Wahl "Dunkel" gilt vor dem System', async ({ page }) => {
            await page.addInitScript(() => {
                localStorage.setItem('ff-theme', 'dark');
            });
            await page.goto('/firefighting/test/');
            await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
            await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(18, 22, 28)');
        });
    });

    test.describe('dunkel (System)', () => {
        test.use({ colorScheme: 'dark' });

        test('dunkle Farben', async ({ page }) => {
            await page.goto('/firefighting/test/');
            await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(18, 22, 28)');
        });

        test('gespeicherte Wahl "Hell" gilt vor dem System', async ({ page }) => {
            await page.addInitScript(() => {
                localStorage.setItem('ff-theme', 'light');
            });
            await page.goto('/firefighting/test/');
            await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(243, 244, 246)');
        });
    });
});
