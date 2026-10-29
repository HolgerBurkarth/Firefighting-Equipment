import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    NETWORK_ERROR_CONSOLE,
    NOT_AUTHENTICATED,
    test,
} from './fixtures';

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

    /**
     * Wartezeit vor dem ersten erneuten Versuch (RETRY_DELAYS_MS[0] in src/auth/session.ts), per
     * page.clock vorgespult statt abgewartet.
     */
    const FIRST_RETRY_MS = 2000;

    test.describe('Störung bei auth/me', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        for (const [label, failure] of [
            ['keine Verbindung', 'abort'],
            ['Server nicht erreichbar (503)', DATABASE_UNAVAILABLE],
        ] as const) {
            test.describe(label, () => {
                let calls = 0;
                test.use({
                    api: {
                        'GET api/v1/auth/me': () => {
                            calls += 1;
                            return calls === 1 ? failure : { status: 200, body: meBody('admin') };
                        },
                    },
                });

                test.beforeEach(async ({ page }) => {
                    calls = 0;
                    await page.clock.install();
                });

                test('führt nie zur Anmeldung, versucht es erneut', async ({ page }) => {
                    await page.goto('/firefighting/test/');
                    await expect(page.getByTestId('connection-notice')).toContainText(
                        'Keine Verbindung zum Server, neuer Versuch läuft automatisch.',
                    );
                    await expect(page).not.toHaveURL(/anmelden/);
                    // Nach dem erneuten Versuch geht es normal weiter.
                    await page.clock.runFor(FIRST_RETRY_MS);
                    await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
                    await expect(page.getByTestId('connection-notice')).toBeHidden();
                    expect(calls).toBe(2);
                });

                test('geschützte Seite bleibt, statt zur Anmeldung zu springen', async ({
                    page,
                }) => {
                    await page.goto('/firefighting/test/dashboard');
                    await expect(page.getByTestId('connection-notice')).toBeVisible();
                    await page.clock.runFor(FIRST_RETRY_MS);
                    await expect(
                        page.getByRole('heading', { level: 1, name: 'Dashboard' }),
                    ).toBeVisible();
                    await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
                });
            });
        }

        test.describe('danach nicht angemeldet', () => {
            let calls = 0;
            test.use({
                api: {
                    'GET api/v1/auth/me': () => {
                        calls += 1;
                        return calls === 1 ? 'abort' : NOT_AUTHENTICATED;
                    },
                },
            });

            test.beforeEach(async ({ page }) => {
                calls = 0;
                await page.clock.install();
            });

            test('erst die echte 401 führt zur Anmeldung', async ({ page }) => {
                await page.goto('/firefighting/test/suchen');
                await expect(page.getByTestId('connection-notice')).toBeVisible();
                await page.clock.runFor(FIRST_RETRY_MS);
                await expect(page).toHaveURL(/anmelden\?weiter=\/suchen$/);
                await expect(page.getByTestId('connection-notice')).toBeHidden();
            });
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
