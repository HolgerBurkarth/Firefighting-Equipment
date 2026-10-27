import { expect, test } from './fixtures';

test.use({ serviceWorkers: 'allow' });

test.describe('Service Worker', () => {
    test('wird aus sw.js mit dem Bereich als Scope registriert', async ({ page }) => {
        await page.goto('/firefighting/test/');
        const registration = await page.evaluate(async () => {
            const ready = await navigator.serviceWorker.ready;
            return { scope: ready.scope, script: ready.active?.scriptURL ?? '' };
        });
        expect(new URL(registration.scope).pathname).toBe('/firefighting/test/');
        expect(new URL(registration.script).pathname).toBe('/firefighting/test/sw.js');
    });

    test('liefert die App-Seite auch offline', async ({ page, context, browserName }) => {
        // context.setOffline() umgeht in Firefox und WebKit den Service Worker (Playwright).
        test.skip(browserName !== 'chromium', 'Offline-Simulation nur in Chromium zuverlässig');
        await page.goto('/firefighting/test/');
        await page.evaluate(async () => {
            await navigator.serviceWorker.ready;
        });
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Startseite' })).toBeVisible();

        await context.setOffline(true);
        await page.goto('/firefighting/test/anmelden');
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();
        await context.setOffline(false);
    });
});
