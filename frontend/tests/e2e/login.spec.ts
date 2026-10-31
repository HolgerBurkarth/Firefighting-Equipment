import type { Page } from '@playwright/test';
import {
    DATABASE_UNAVAILABLE,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    NETWORK_ERROR_CONSOLE,
    TEST_TENANT,
    test,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { NO_DEVICES } from './support/devices';
import { SERVICE_OVERVIEW } from './support/service';

const LOGIN = 'POST api/v1/auth/login';

function apiError(status: number, error: string, headers?: Record<string, string>): MockResponse {
    return {
        status,
        body: { error, message: 'Meldung des Servers.' },
        ...(headers && { headers }),
    };
}

async function fillAndSubmit(page: Page, email = 'anna@example.org', password = 'richtig-1234') {
    await page.getByLabel('E-Mail-Adresse').fill(email);
    await page.getByLabel('Passwort', { exact: true }).fill(password);
    await page.getByTestId('login-submit').click();
}

test.describe('Anmeldeseite', () => {
    test('Logo, Name und Farbe der Stadt', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        await expect(page.getByTestId('brand-name')).toHaveText('Feuerwehr Test');
        await expect(page.getByTestId('brand-logo')).toHaveAttribute('src', 'api/v1/app-icon/512');
        await expect(page.getByTestId('brand-name')).toHaveCSS(
            'border-left-color',
            'rgb(183, 28, 28)',
        );
        await expect(page.getByRole('link', { name: /registrieren/i })).toHaveCount(0);
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
                body: {
                    ...TEST_TENANT.body,
                    accent_color: '#1d3557',
                    logo_url: logoUrls['512'],
                    logo_urls: logoUrls,
                },
            },
        });

        test('zeigt das Logo der Stadt', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden');
            await expect(page.getByTestId('brand-logo')).toHaveAttribute('src', logoUrls['512']);
            await expect(page.getByTestId('brand-name')).toHaveCSS(
                'border-left-color',
                'rgb(29, 53, 87)',
            );
        });
    });

    test('Super-Admin unter service', async ({ page }) => {
        await page.goto('/firefighting/service/anmelden');
        await expect(page.getByTestId('brand-name')).toHaveText('Super-Admin');
        await expect(page.getByTestId('brand-logo')).toHaveAttribute('src', 'api/v1/app-icon/512');
    });

    test('Felder für Passwort-Manager und große Bedienelemente', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        const email = page.getByLabel('E-Mail-Adresse');
        await expect(email).toHaveAttribute('autocomplete', 'username');
        await expect(email).toHaveAttribute('type', 'email');
        await expect(page.getByLabel('Passwort', { exact: true })).toHaveAttribute(
            'autocomplete',
            'current-password',
        );
        for (const control of [
            page.getByRole('button', { name: 'Passwort anzeigen' }),
            page.getByTestId('login-submit'),
            email,
        ]) {
            const box = await control.boundingBox();
            expect(box?.height).toBeGreaterThanOrEqual(44);
            expect(box?.width).toBeGreaterThanOrEqual(44);
        }
    });

    test('Auge-Symbol zeigt und verbirgt das Passwort', async ({ page }) => {
        await page.goto('/firefighting/test/anmelden');
        const password = page.getByLabel('Passwort', { exact: true });
        const toggle = page.getByRole('button', { name: 'Passwort anzeigen' });
        await password.fill('geheim-geheim');
        await expect(password).toHaveAttribute('type', 'password');
        await toggle.click();
        await expect(password).toHaveAttribute('type', 'text');
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        await toggle.click();
        await expect(password).toHaveAttribute('type', 'password');
    });

    test('leere Felder: Hinweis am Feld, keine Anfrage', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/anmelden');
        await page.getByTestId('login-submit').click();
        await expect(page.getByText('Bitte geben Sie Ihre E-Mail-Adresse ein.')).toBeVisible();
        await expect(page.getByLabel('E-Mail-Adresse')).toBeFocused();
        await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
        await page.getByTestId('login-submit').click();
        await expect(page.getByText('Bitte geben Sie Ihr Passwort ein.')).toBeVisible();
        await expect(page.getByLabel('Passwort', { exact: true })).toBeFocused();
        expect(apiRequests).toEqual([]);
    });

    test.describe('Stadt unbekannt', () => {
        test.use({
            tenant: { status: 404, body: { error: 'tenant_not_found', message: 'Unbekannt.' } },
            allowedConsoleError: HTTP_ERROR_CONSOLE,
        });

        test('blendet das Formular aus', async ({ page }) => {
            await page.goto('/firefighting/gibtsnicht/anmelden');
            await expect(page.getByRole('alert')).toContainText('gibt es unter dieser Adresse');
            await expect(page.getByLabel('E-Mail-Adresse')).toHaveCount(0);
        });
    });

    test.describe('Stadt gesperrt', () => {
        test.use({
            tenant: { status: 403, body: { error: 'tenant_inactive', message: 'Gesperrt.' } },
            allowedConsoleError: HTTP_ERROR_CONSOLE,
        });

        test('blendet das Formular aus', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden');
            await expect(page.getByRole('alert')).toContainText('Feuerwehr ist gesperrt');
            await expect(page.getByLabel('E-Mail-Adresse')).toHaveCount(0);
        });
    });
});

test.describe('Erfolgreiche Anmeldung', () => {
    for (const [role, area, target] of [
        ['user', 'test', 'suchen'],
        ['admin', 'test', 'dashboard'],
        ['superadmin', 'service', 'dashboard'],
    ] as const) {
        test.describe(role, () => {
            test.use({
                api: {
                    [LOGIN]: { status: 200, body: meBody(role) },
                    ...NO_DEVICES,
                    ...ADMIN_OVERVIEW,
                    ...SERVICE_OVERVIEW,
                },
            });

            test(`führt zu ${target}`, async ({ page, apiRequests }) => {
                await page.goto(`/firefighting/${area}/anmelden`);
                await fillAndSubmit(page);
                await expect(page).toHaveURL(new RegExp(`/firefighting/${area}/${target}$`));
                expect(apiRequests[0]?.body).toEqual({
                    email: 'anna@example.org',
                    password: 'richtig-1234',
                });
                expect(apiRequests[0]?.area).toBe(area);
            });
        });
    }

    test.describe('mit Rückkehradresse', () => {
        test.use({ api: { [LOGIN]: { status: 200, body: meBody('admin') }, ...ADMIN_OVERVIEW } });

        test('kehrt zur vorherigen Seite zurück', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden?weiter=%2Fdashboard');
            await fillAndSubmit(page);
            await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
        });

        test('fremde Adressen werden ignoriert', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden?weiter=%2F%2Fboese.example%2F');
            await fillAndSubmit(page);
            await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
        });

        test('Enter im Passwortfeld meldet an', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden');
            await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
            await page.getByLabel('Passwort', { exact: true }).fill('richtig-1234');
            await page.getByLabel('Passwort', { exact: true }).press('Enter');
            await expect(page).toHaveURL(/\/dashboard$/);
        });
    });

    test.describe('Seite für die Rolle gesperrt', () => {
        test.use({ api: { [LOGIN]: { status: 200, body: meBody('user') }, ...NO_DEVICES } });

        test('führt zur Startseite der Rolle', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden?weiter=%2Fdashboard');
            await fillAndSubmit(page);
            await expect(page).toHaveURL(/\/firefighting\/test\/suchen$/);
        });
    });
});

test.describe('Fehler beim Anmelden', () => {
    test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

    const cases = [
        ['invalid_credentials', apiError(401, 'invalid_credentials'), 'Passwort ist falsch'],
        ['account_locked', apiError(403, 'account_locked'), 'Ihr Konto ist gesperrt'],
        ['pending_approval', apiError(403, 'pending_approval'), 'wartet auf Freischaltung'],
        ['tenant_inactive', apiError(403, 'tenant_inactive'), 'Feuerwehr ist gesperrt'],
        ['database_unavailable', DATABASE_UNAVAILABLE, 'nicht erreichbar'],
        ['network', 'abort', 'Keine Verbindung zum Server'],
    ] as const;

    for (const [code, response, text] of cases) {
        test.describe(code, () => {
            test.use({ api: { [LOGIN]: response } });

            test('verständliche Meldung, Code klein', async ({ page }) => {
                await page.goto('/firefighting/test/anmelden');
                await fillAndSubmit(page);
                await expect(page.getByRole('alert')).toContainText(text);
                await expect(page.getByTestId('error-code')).toHaveText(`Code: ${code}`);
                await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.org');
                // Nur bei falschem Passwort wird das Passwortfeld geleert.
                await expect(page.getByLabel('Passwort', { exact: true })).toHaveValue(
                    code === 'invalid_credentials' ? '' : 'richtig-1234',
                );
                await expect(page).toHaveURL(/anmelden$/);
            });
        });
    }

    test.describe('gesperrtes Konto unter service', () => {
        test.use({ api: { [LOGIN]: apiError(403, 'account_locked') } });

        test('verweist auf einen anderen Super-Admin', async ({ page }) => {
            await page.goto('/firefighting/service/anmelden');
            await fillAndSubmit(page);
            await expect(page.getByRole('alert')).toContainText('anderen Super-Admin');
        });
    });

    test.describe('falsches Passwort', () => {
        test.use({ api: { [LOGIN]: apiError(401, 'invalid_credentials') } });

        test('setzt den Fokus ins Passwortfeld', async ({ page }) => {
            await page.goto('/firefighting/test/anmelden');
            await fillAndSubmit(page);
            await expect(page.getByLabel('Passwort', { exact: true })).toBeFocused();
        });
    });

    test.describe('zu viele Versuche', () => {
        test.use({
            api: { [LOGIN]: apiError(429, 'too_many_attempts', { 'Retry-After': '3' }) },
        });

        test('zeigt die Wartezeit und sperrt den Knopf bis dahin', async ({ page }) => {
            // Die 3 s aus Retry-After werden vorgespult statt abgewartet.
            await page.clock.install();
            await page.goto('/firefighting/test/anmelden');
            await fillAndSubmit(page);
            await expect(page.getByRole('alert')).toContainText(
                'Zu viele Versuche. Bitte versuchen Sie es in 3 Sekunden erneut.',
            );
            const submit = page.getByTestId('login-submit');
            await expect(submit).toBeDisabled();
            await expect(submit).toContainText('wieder möglich in 0:0');
            await page.clock.runFor(3000);
            await expect(submit).toBeEnabled();
            await expect(submit).toHaveText('Anmelden');
        });
    });

    test.describe('E-Mail nicht bestätigt', () => {
        test.use({
            api: {
                [LOGIN]: apiError(403, 'email_not_verified'),
                'POST api/v1/auth/email/resend': {
                    status: 202,
                    body: { message: 'Falls eine unbestätigte Registrierung besteht …' },
                },
            },
        });

        test('Mail erneut senden mit Hinweis auf Spam-Ordner', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/anmelden');
            await fillAndSubmit(page);
            await expect(page.getByRole('alert')).toContainText('noch nicht bestätigt');
            await page.getByRole('button', { name: 'Bestätigungsmail erneut senden' }).click();
            await expect(page.getByText('Falls eine unbestätigte Registrierung')).toBeVisible();
            await expect(page.getByTestId('mail-hint')).toContainText('Spam-Ordner');
            await expect(page.getByTestId('mail-hint')).toContainText('feuerwehr-app@prodad.de');
            await expect(
                page.getByRole('button', { name: 'Bestätigungsmail erneut senden' }),
            ).toHaveCount(0);
            const resend = apiRequests.find((r) => r.path === 'api/v1/auth/email/resend');
            expect(resend?.body).toEqual({ email: 'anna@example.org' });
        });
    });
});

test.describe('Rückkehr nach Ablauf der Sitzung', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': {
                status: 401,
                body: { error: 'not_authenticated', message: 'x' },
            },
            [LOGIN]: { status: 200, body: meBody('admin') },
            ...ADMIN_OVERVIEW,
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('nach der Anmeldung zurück zur vorherigen Seite', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        await expect(page).toHaveURL(/anmelden\?weiter=\/dashboard$/);
        await fillAndSubmit(page);
        await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible();
    });
});

test.describe('Tastatur', () => {
    test('Reihenfolge E-Mail, Passwort, Auge, Anmelden', async ({
        page,
        browserName,
        isMobile,
    }) => {
        // WebKit springt mit Tab ohne Systemeinstellung nicht auf Knöpfe; am Handy keine Tab-Taste.
        test.skip(browserName === 'webkit' || isMobile, 'Tab-Reihenfolge nur in Chromium/Firefox');
        await page.goto('/firefighting/test/anmelden');
        await page.getByLabel('E-Mail-Adresse').focus();
        await page.keyboard.press('Tab');
        await expect(page.getByLabel('Passwort', { exact: true })).toBeFocused();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('button', { name: 'Passwort anzeigen' })).toBeFocused();
        await page.keyboard.press('Space');
        await expect(page.getByLabel('Passwort', { exact: true })).toHaveAttribute('type', 'text');
        await page.keyboard.press('Tab');
        await expect(page.getByTestId('login-submit')).toBeFocused();
    });
});
