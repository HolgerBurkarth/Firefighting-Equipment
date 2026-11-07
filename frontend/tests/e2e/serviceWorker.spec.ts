import { expect, HTTP_ERROR_CONSOLE, test } from './fixtures';

test.use({ serviceWorkers: 'allow' });

test.describe('Service Worker', () => {
    test('wird aus sw.js mit dem Bereich als Scope registriert', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        const registration = await page.evaluate(async () => {
            const ready = await navigator.serviceWorker.ready;
            return { scope: ready.scope, script: ready.active?.scriptURL ?? '' };
        });
        expect(new URL(registration.scope).pathname).toBe('/firefighting/test/');
        expect(new URL(registration.script).pathname).toBe('/firefighting/test/sw.js');
    });

    test.describe('Adressen des Claude-Zugangs', () => {
        // Antworten mit 404/405 meldet der Browser in der Konsole.
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('beantwortet immer der Server, auch mit installiertem Service Worker', async ({
            page,
        }) => {
            await page.goto('/firefighting/test/anmelden');
            await page.evaluate(async () => {
                await navigator.serviceWorker.ready;
            });

            // Ohne Datenbank: 405 bzw. 404 kommen vom Router, vor dem Laden der Stadt. Mit dem
            // Rückfall des Service Workers wären es 200 und die App-Seite.
            const expected: [string, number][] = [
                ['mcp', 405],
                ['oauth/register', 405],
                ['.well-known/gibt-es-nicht', 404],
            ];
            for (const [path, status] of expected) {
                const response = await page.goto(`/firefighting/test/${path}`);
                expect(response?.status(), path).toBe(status);
                expect(response?.headers()['content-type'], path).toBe(
                    'application/json; charset=utf-8',
                );
            }

            // claude-verbinden ist eine Route der Stadt: Ob die Stadt ladbar ist, hängt von der
            // Datenbank des Testservers ab; HTML (die App-Seite) darf es nie sein.
            const connect = await page.goto(
                '/firefighting/test/claude-verbinden?response_type=code&client_id=probe-x',
            );
            expect(connect?.headers()['content-type']).toBe('application/json; charset=utf-8');
        });
    });

    test('liefert die App-Seite auch offline', async ({ page, context, browserName }) => {
        // context.setOffline() umgeht in Firefox und WebKit den Service Worker (Playwright).
        test.skip(browserName !== 'chromium', 'Offline-Simulation nur in Chromium zuverlässig');
        // Öffentliche Seite (ohne auth/me), damit die Anfragen offline nur die App-Seite betreffen.
        await page.goto('/firefighting/test/anmelden');
        await page.evaluate(async () => {
            await navigator.serviceWorker.ready;
        });
        await page.reload();
        await expect(page.getByRole('heading', { level: 1, name: 'Anmelden' })).toBeVisible();

        await context.setOffline(true);
        await page.goto('/firefighting/test/passwort-vergessen-gibt-es-noch-nicht');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
        await context.setOffline(false);
    });
});
