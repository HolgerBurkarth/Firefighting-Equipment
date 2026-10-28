import type { Page } from '@playwright/test';
import {
    expect,
    NETWORK_ERROR_CONSOLE,
    test,
    type MockResponse,
    type TenantResponse,
} from './fixtures';

const REGISTER = 'POST api/v1/auth/register';
const VERIFY = 'POST api/v1/auth/email/verify';
const RESEND = 'POST api/v1/auth/email/resend';
const TOKEN = 'Vr1fy_aus-der-Mail_0123456789abcdefghijklmnopq';
const REGISTERED_MESSAGE =
    'Vielen Dank. Wir haben Ihnen eine E-Mail geschickt; bitte bestätigen Sie darin Ihre Adresse.';
const VERIFIED_MESSAGE =
    'Ihre E-Mail-Adresse ist bestätigt. Ein Admin Ihrer Feuerwehr schaltet Ihr Konto frei; Sie erhalten dann eine E-Mail.';
const RESENT_MESSAGE =
    'Falls zu dieser Adresse eine unbestätigte Registrierung besteht, erhalten Sie in Kürze eine E-Mail.';

function apiError(
    status: number,
    error: string,
    message = 'Meldung des Servers.',
    headers?: Record<string, string>,
): MockResponse {
    return { status, body: { error, message }, ...(headers && { headers }) };
}

/** Alle Adressen, die die Seite anfragt (Code und Token dürfen in keiner stehen). */
function recordUrls(page: Page): string[] {
    const urls: string[] = [];
    page.on('request', (request) => urls.push(request.url()));
    return urls;
}

const field = {
    code: (page: Page) => page.getByLabel('Einladungscode'),
    name: (page: Page) => page.getByLabel('Name', { exact: true }),
    email: (page: Page) => page.getByLabel('E-Mail-Adresse'),
    password: (page: Page) => page.getByLabel('Passwort', { exact: true }),
};

async function fillForm(page: Page, code = 'ABCD-EFGH-JKMN'): Promise<void> {
    await field.code(page).fill(code);
    await field.name(page).fill('Anna Beispiel');
    await field.email(page).fill('anna@example.org');
    await field.password(page).fill('Kxq7-Lmw2!-lang');
}

function submit(page: Page) {
    return page.getByRole('button', { name: 'Registrieren' }).click();
}

test.describe('Registrieren', () => {
    test.describe('erfolgreich', () => {
        test.use({ api: { [REGISTER]: { status: 202, body: { message: REGISTERED_MESSAGE } } } });

        test('Code aus dem QR-Link: übernommen, aus der Adresszeile entfernt, per POST', async ({
            page,
            apiRequests,
        }) => {
            const urls = recordUrls(page);
            await page.goto('/firefighting/test/registrieren#code=abcd efgh-jkmn');
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Registrieren');
            await expect(page).toHaveURL(/\/firefighting\/test\/registrieren$/);
            await expect(field.code(page)).toHaveValue('ABCD-EFGH-JKMN');

            await field.name(page).fill('Anna Beispiel');
            await field.email(page).fill('anna@example.org');
            await field.password(page).fill('Kxq7-Lmw2!-lang');
            await submit(page);

            await expect(page.getByTestId('register-sent')).toHaveText(REGISTERED_MESSAGE);
            await expect(page.getByTestId('mail-hint')).toContainText('feuerwehr-app@prodad.de');
            await expect(page.getByText('24 Stunden gültig')).toBeVisible();
            expect(apiRequests[0]?.body).toEqual({
                code: 'ABCD-EFGH-JKMN',
                email: 'anna@example.org',
                password: 'Kxq7-Lmw2!-lang',
                name: 'Anna Beispiel',
            });
            expect(urls.filter((url) => /abcd(%20| )efgh|ABCD-?EFGH/i.test(url))).toEqual([]);

            await page.getByRole('link', { name: 'Bestätigungsmail erneut senden' }).click();
            await expect(page).toHaveURL(/\/firefighting\/test\/email-bestaetigen$/);
            await expect(page.getByLabel('E-Mail-Adresse')).toBeVisible();
        });

        test('ohne Code im Link: Feld leer; Eingabe in beliebiger Schreibweise', async ({
            page,
            apiRequests,
        }) => {
            await page.goto('/firefighting/test/registrieren');
            await expect(field.code(page)).toHaveValue('');
            await fillForm(page, ' abcdefghjkmn ');
            // Beim Verlassen des Feldes einheitlich angezeigt.
            await expect(field.code(page)).toHaveValue('ABCD-EFGH-JKMN');
            await submit(page);
            await expect(page.getByTestId('register-sent')).toBeVisible();
            expect(apiRequests[0]?.body).toMatchObject({ code: 'ABCD-EFGH-JKMN' });
        });

        test('Pflichtfelder: Hinweis am ersten offenen Feld, keine Anfrage', async ({
            page,
            apiRequests,
        }) => {
            await page.goto('/firefighting/test/registrieren');
            await submit(page);
            await expect(page.getByText('Bitte geben Sie den Einladungscode ein.')).toBeVisible();
            await expect(page.getByText('Bitte geben Sie Ihren Namen ein.')).toBeVisible();
            await expect(page.getByText('Bitte geben Sie Ihre E-Mail-Adresse ein.')).toBeVisible();
            await expect(field.code(page)).toBeFocused();
            await expect(field.code(page)).toHaveAttribute('aria-invalid', 'true');
            expect(apiRequests).toEqual([]);
        });

        test('Code mit falschem Aufbau: Hinweis am Feld, keine Anfrage', async ({
            page,
            apiRequests,
        }) => {
            await page.goto('/firefighting/test/registrieren');
            // 0 und O gibt es in Codes nicht (Verwechslung).
            await fillForm(page, 'ABCD-EFGH-JK0N');
            await submit(page);
            await expect(page.getByText('Der Einladungscode hat 12 Zeichen')).toBeVisible();
            await expect(field.code(page)).toBeFocused();
            expect(apiRequests).toEqual([]);
        });

        test('Live-Prüfung des Passworts mit Name und E-Mail', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/registrieren');
            await fillForm(page);
            const personal = page
                .getByTestId('password-rules')
                .getByRole('listitem')
                .filter({ hasText: 'Keine Teile von Name' });
            await field.password(page).fill('Beispiel-Passwort-1');
            await expect(personal).toHaveAttribute('data-state', 'error');
            await submit(page);
            await expect(field.password(page)).toBeFocused();
            expect(apiRequests).toEqual([]);
        });

        test('Formular für Passwort-Manager und Handys', async ({ page }) => {
            await page.goto('/firefighting/test/registrieren');
            await expect(field.code(page)).toHaveAttribute('autocomplete', 'off');
            await expect(field.name(page)).toHaveAttribute('autocomplete', 'name');
            await expect(field.email(page)).toHaveAttribute('autocomplete', 'username');
            await expect(field.email(page)).toHaveAttribute('type', 'email');
            await expect(field.password(page)).toHaveAttribute('autocomplete', 'new-password');
            const box = await page.getByRole('button', { name: 'Registrieren' }).boundingBox();
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        });
    });

    test.describe('Fehler', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        test.describe('ungültiger Einladungscode', () => {
            test.use({ api: { [REGISTER]: apiError(400, 'invalid_invitation_code') } });

            test('Meldung am Feld, Eingaben bleiben', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await fillForm(page);
                await submit(page);
                await expect(page.getByText('Einladungscode ist ungültig')).toBeVisible();
                await expect(field.code(page)).toBeFocused();
                await expect(field.name(page)).toHaveValue('Anna Beispiel');
                await expect(field.password(page)).toHaveValue('Kxq7-Lmw2!-lang');
            });
        });

        test.describe('Sperrliste (nur Server)', () => {
            test.use({
                api: {
                    [REGISTER]: apiError(
                        422,
                        'weak_password',
                        'Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.',
                    ),
                },
            });

            test('Meldung des Servers am Passwort', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await fillForm(page);
                await submit(page);
                await expect(page.getByTestId('password-server-error')).toContainText(
                    'zu verbreitet',
                );
                await expect(field.password(page)).toBeFocused();
            });
        });

        test.describe('E-Mail-Adresse abgelehnt', () => {
            test.use({
                api: {
                    [REGISTER]: apiError(
                        400,
                        'invalid_request',
                        'Bitte eine gültige E-Mail-Adresse angeben.',
                    ),
                },
            });

            test('Meldung am Feld E-Mail', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await fillForm(page);
                await submit(page);
                await expect(
                    page.getByText('Bitte eine gültige E-Mail-Adresse angeben.'),
                ).toBeVisible();
                await expect(field.email(page)).toBeFocused();
            });
        });

        test.describe('zu viele Versuche', () => {
            test.use({
                api: {
                    [REGISTER]: apiError(429, 'too_many_attempts', 'x', { 'Retry-After': '600' }),
                },
            });

            test('nennt die Wartezeit', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await fillForm(page);
                await submit(page);
                await expect(page.getByRole('alert')).toContainText('in etwa 10 Minuten');
                await expect(page.getByTestId('error-code')).toHaveText('Code: too_many_attempts');
            });
        });

        test.describe('keine Verbindung', () => {
            test.use({ api: { [REGISTER]: 'abort' } });

            test('Eingaben bleiben erhalten', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await fillForm(page);
                await submit(page);
                await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
                await expect(field.email(page)).toHaveValue('anna@example.org');
                await expect(field.password(page)).toHaveValue('Kxq7-Lmw2!-lang');
            });
        });

        test.describe('unbekannte Stadt', () => {
            const unknown: TenantResponse = {
                status: 404,
                body: { error: 'tenant_not_found', message: 'Unbekannt.' },
            };
            test.use({ tenant: unknown });

            test('kein Formular', async ({ page }) => {
                await page.goto('/firefighting/test/registrieren');
                await expect(page.getByRole('alert')).toContainText(
                    'gibt es unter dieser Adresse nicht',
                );
                await expect(field.code(page)).toHaveCount(0);
            });
        });
    });

    test('unter service gibt es keine Registrierung', async ({ page }) => {
        await page.goto('/firefighting/service/registrieren');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});

test.describe('E-Mail-Adresse bestätigen', () => {
    test.describe('erfolgreich', () => {
        test.use({ api: { [VERIFY]: { status: 200, body: { message: VERIFIED_MESSAGE } } } });

        test('Token aus dem Fragment, erst per Knopf gesendet', async ({ page, apiRequests }) => {
            const urls = recordUrls(page);
            await page.goto(`/firefighting/test/email-bestaetigen#token=${TOKEN}`);
            await expect(page).toHaveURL(/\/firefighting\/test\/email-bestaetigen$/);
            const button = page.getByRole('button', { name: 'E-Mail-Adresse bestätigen' });
            await expect(button).toBeVisible();
            // Nicht schon beim Öffnen (Virenscanner öffnen Links vorab).
            expect(apiRequests).toEqual([]);

            await button.click();
            await expect(page.getByTestId('email-verified')).toHaveText(VERIFIED_MESSAGE);
            expect(apiRequests[0]?.body).toEqual({ token: TOKEN });
            expect(urls.filter((url) => url.includes(TOKEN))).toEqual([]);

            await page.getByRole('link', { name: 'Zur Anmeldung' }).click();
            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
        });
    });

    test.describe('Link abgelaufen oder benutzt', () => {
        test.use({
            allowedConsoleError: NETWORK_ERROR_CONSOLE,
            api: {
                [VERIFY]: apiError(400, 'invalid_token'),
                [RESEND]: { status: 202, body: { message: RESENT_MESSAGE } },
            },
        });

        test('Meldung und neuer Link per Mail', async ({ page, apiRequests }) => {
            await page.goto(`/firefighting/test/email-bestaetigen#token=${TOKEN}`);
            await page.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }).click();
            await expect(page.getByRole('alert')).toContainText(
                'abgelaufen oder wurde schon benutzt',
            );
            await expect(
                page.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }),
            ).toHaveCount(0);

            await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
            await page.getByRole('button', { name: 'Neuen Link senden' }).click();
            await expect(page.getByTestId('resend-sent')).toHaveText(RESENT_MESSAGE);
            await expect(page.getByTestId('mail-hint')).toBeVisible();
            expect(apiRequests.map((request) => [request.path, request.body])).toEqual([
                ['api/v1/auth/email/verify', { token: TOKEN }],
                ['api/v1/auth/email/resend', { email: 'anna@example.org' }],
            ]);
        });
    });

    test.describe('ohne Token', () => {
        test.use({ api: { [RESEND]: { status: 202, body: { message: RESENT_MESSAGE } } } });

        test('Hinweis und neuer Link; leeres Feld ohne Anfrage', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/email-bestaetigen');
            await expect(
                page.getByText('Bitte öffnen Sie den Link aus der Bestätigungsmail.'),
            ).toBeVisible();
            await page.getByRole('button', { name: 'Neuen Link senden' }).click();
            await expect(page.getByText('Bitte geben Sie Ihre E-Mail-Adresse ein.')).toBeVisible();
            await expect(page.getByLabel('E-Mail-Adresse')).toBeFocused();
            expect(apiRequests).toEqual([]);

            await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
            await page.getByRole('button', { name: 'Neuen Link senden' }).click();
            await expect(page.getByTestId('resend-sent')).toBeVisible();
        });
    });

    test.describe('Störungen', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        test.describe('zu viele Versuche', () => {
            test.use({
                api: {
                    [VERIFY]: apiError(429, 'too_many_attempts', 'x', { 'Retry-After': '120' }),
                },
            });

            test('nennt die Wartezeit, Knopf bleibt für einen neuen Versuch', async ({ page }) => {
                await page.goto(`/firefighting/test/email-bestaetigen#token=${TOKEN}`);
                await page.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }).click();
                await expect(page.getByRole('alert')).toContainText('in etwa 2 Minuten');
                await expect(
                    page.getByRole('button', { name: 'E-Mail-Adresse bestätigen' }),
                ).toBeEnabled();
            });
        });

        test.describe('keine Verbindung beim neuen Link', () => {
            test.use({ api: { [RESEND]: 'abort' } });

            test('Meldung, Eingabe bleibt', async ({ page }) => {
                await page.goto('/firefighting/test/email-bestaetigen');
                await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
                await page.getByRole('button', { name: 'Neuen Link senden' }).click();
                await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
                await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.org');
            });
        });
    });

    test('unter service gibt es die Seite nicht', async ({ page }) => {
        await page.goto('/firefighting/service/email-bestaetigen');
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});
