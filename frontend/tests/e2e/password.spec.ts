import type { Page } from '@playwright/test';
import { expect, NETWORK_ERROR_CONSOLE, test, type MockResponse } from './fixtures';

const REQUEST = 'POST api/v1/auth/password-reset/request';
const CONFIRM = 'POST api/v1/auth/password-reset/confirm';
const TOKEN = 'Tk3n_aus-der-Mail_0123456789abcdefghijklmnopq';
const SENT_MESSAGE =
    'Falls zu dieser Adresse ein Konto besteht, erhalten Sie in Kürze eine E-Mail mit einem Link zum Zurücksetzen des Passworts.';
const CHANGED_MESSAGE =
    'Das Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.';

function apiError(
    status: number,
    error: string,
    message = 'Meldung des Servers.',
    headers?: Record<string, string>,
): MockResponse {
    return { status, body: { error, message }, ...(headers && { headers }) };
}

/** Alle Adressen, die die Seite anfragt (das Token darf in keiner stehen). */
function recordUrls(page: Page): string[] {
    const urls: string[] = [];
    page.on('request', (request) => urls.push(request.url()));
    return urls;
}

function newPassword(page: Page) {
    return page.getByLabel('Neues Passwort', { exact: true });
}

test.describe('Passwort vergessen', () => {
    test.describe('Anfrage angenommen', () => {
        test.use({ api: { [REQUEST]: { status: 202, body: { message: SENT_MESSAGE } } } });

        for (const area of ['test', 'service']) {
            test(`Link auf der Anmeldeseite und Hinweis auf den Spam-Ordner (${area})`, async ({
                page,
                apiRequests,
            }) => {
                await page.goto(`/firefighting/${area}/anmelden`);
                await page.getByRole('link', { name: 'Passwort vergessen?' }).click();
                await expect(page).toHaveURL(
                    new RegExp(`/firefighting/${area}/passwort-vergessen$`),
                );
                const email = page.getByLabel('E-Mail-Adresse');
                await expect(email).toHaveAttribute('autocomplete', 'username');
                await email.fill('anna@example.org');
                await page.getByRole('button', { name: 'Link anfordern' }).click();
                await expect(page.getByTestId('reset-sent')).toHaveText(SENT_MESSAGE);
                await expect(page.getByTestId('mail-hint')).toContainText('Spam-Ordner');
                await expect(page.getByTestId('mail-hint')).toContainText(
                    'feuerwehr-app@prodad.de',
                );
                expect(apiRequests[0]?.body).toEqual({ email: 'anna@example.org' });
                expect(apiRequests[0]?.area).toBe(area);
                await page.getByRole('link', { name: 'Zurück zur Anmeldung' }).click();
                await expect(page).toHaveURL(/anmelden$/);
            });
        }

        test('leeres Feld: Hinweis ohne Anfrage', async ({ page, apiRequests }) => {
            await page.goto('/firefighting/test/passwort-vergessen');
            await page.getByRole('button', { name: 'Link anfordern' }).click();
            await expect(page.getByText('Bitte geben Sie Ihre E-Mail-Adresse ein.')).toBeVisible();
            await expect(page.getByLabel('E-Mail-Adresse')).toBeFocused();
            expect(apiRequests).toEqual([]);
        });
    });

    test.describe('Fehler', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        test.describe('ungültige Adresse', () => {
            test.use({
                api: {
                    [REQUEST]: apiError(400, 'invalid_request', 'Die E-Mail-Adresse ist ungültig.'),
                },
            });

            test('Meldung des Servers am Feld', async ({ page }) => {
                await page.goto('/firefighting/test/passwort-vergessen');
                await page.getByLabel('E-Mail-Adresse').fill('anna@localhost');
                await page.getByRole('button', { name: 'Link anfordern' }).click();
                await expect(page.getByText('Die E-Mail-Adresse ist ungültig.')).toBeVisible();
                await expect(page.getByLabel('E-Mail-Adresse')).toHaveAttribute(
                    'aria-invalid',
                    'true',
                );
            });
        });

        test.describe('zu viele Anfragen', () => {
            test.use({
                api: {
                    [REQUEST]: apiError(429, 'too_many_attempts', 'x', { 'Retry-After': '1800' }),
                },
            });

            test('nennt die Wartezeit', async ({ page }) => {
                await page.goto('/firefighting/test/passwort-vergessen');
                await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
                await page.getByRole('button', { name: 'Link anfordern' }).click();
                await expect(page.getByRole('alert')).toContainText('in etwa 30 Minuten');
                await expect(page.getByTestId('error-code')).toHaveText('Code: too_many_attempts');
            });
        });

        test.describe('keine Verbindung', () => {
            test.use({ api: { [REQUEST]: 'abort' } });

            test('Eingabe bleibt erhalten', async ({ page }) => {
                await page.goto('/firefighting/test/passwort-vergessen');
                await page.getByLabel('E-Mail-Adresse').fill('anna@example.org');
                await page.getByRole('button', { name: 'Link anfordern' }).click();
                await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
                await expect(page.getByLabel('E-Mail-Adresse')).toHaveValue('anna@example.org');
            });
        });
    });
});

test.describe('Passwort zurücksetzen', () => {
    test.describe('erfolgreich', () => {
        test.use({ api: { [CONFIRM]: { status: 200, body: { message: CHANGED_MESSAGE } } } });

        for (const area of ['test', 'service']) {
            test(`Token aus dem Fragment, per POST, danach Anmeldung (${area})`, async ({
                page,
                apiRequests,
            }) => {
                const urls = recordUrls(page);
                await page.goto(`/firefighting/${area}/passwort-zuruecksetzen#token=${TOKEN}`);
                await expect(page.getByRole('heading', { level: 1 })).toHaveText(
                    'Neues Passwort festlegen',
                );
                // Das Token verschwindet aus der Adresszeile.
                await expect(page).toHaveURL(
                    new RegExp(`/firefighting/${area}/passwort-zuruecksetzen$`),
                );
                await expect(newPassword(page)).toHaveAttribute('autocomplete', 'new-password');
                await newPassword(page).fill('Kxq7-Lmw2!-lang');
                await page.getByRole('button', { name: 'Passwort speichern' }).click();
                await expect(page).toHaveURL(new RegExp(`/firefighting/${area}/anmelden$`));
                await expect(page.getByTestId('login-notice')).toHaveText(CHANGED_MESSAGE);
                expect(apiRequests[0]?.body).toEqual({
                    token: TOKEN,
                    new_password: 'Kxq7-Lmw2!-lang',
                });
                expect(urls.filter((url) => url.includes(TOKEN))).toEqual([]);
            });
        }

        test('Live-Prüfung: Länge und Name der Stadt, Absenden erst danach', async ({
            page,
            apiRequests,
        }) => {
            await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
            const rules = page.getByTestId('password-rules');
            const length = rules.getByRole('listitem').filter({ hasText: 'Mindestens 10 Zeichen' });
            const personal = rules
                .getByRole('listitem')
                .filter({ hasText: 'Keine Teile von Name' });
            await expect(length).toHaveAttribute('data-state', 'neutral');

            await newPassword(page).fill('kurz');
            await expect(length).toHaveAttribute('data-state', 'error');
            await page.getByRole('button', { name: 'Passwort speichern' }).click();
            await expect(newPassword(page)).toBeFocused();
            await expect(newPassword(page)).toHaveAttribute('aria-invalid', 'true');

            // „test“ ist das Kürzel der Stadt (4 Zeichen).
            await newPassword(page).fill('Mein-TEST-Passwort');
            await expect(length).toHaveAttribute('data-state', 'ok');
            await expect(personal).toHaveAttribute('data-state', 'error');
            await page.getByRole('button', { name: 'Passwort speichern' }).click();
            expect(apiRequests).toEqual([]);

            // Das allgemeine Wort „Feuerwehr“ aus dem Stadtnamen ist erlaubt.
            await newPassword(page).fill('Unsere Feuerwehr fährt rot');
            await expect(personal).toHaveAttribute('data-state', 'ok');
            await expect(newPassword(page)).not.toHaveAttribute('aria-invalid', 'true');

            await newPassword(page).fill('x'.repeat(129));
            await expect(rules).toContainText('Höchstens 128 Zeichen');
        });

        test('Auge-Symbol', async ({ page }) => {
            await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
            await newPassword(page).fill('Kxq7-Lmw2!-lang');
            await page.getByRole('button', { name: 'Passwort anzeigen' }).click();
            await expect(newPassword(page)).toHaveAttribute('type', 'text');
        });
    });

    test('ohne Token: Hinweis und Weg zum neuen Link', async ({ page }) => {
        await page.goto('/firefighting/test/passwort-zuruecksetzen');
        await expect(page.getByRole('alert')).toContainText('Link ist unvollständig');
        await expect(newPassword(page)).toHaveCount(0);
        await page.getByRole('link', { name: 'Neuen Link anfordern' }).click();
        await expect(page).toHaveURL(/passwort-vergessen$/);
    });

    test.describe('Fehler', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        test.describe('Link abgelaufen oder benutzt', () => {
            test.use({ api: { [CONFIRM]: apiError(400, 'invalid_token') } });

            test('Meldung und Weg zum neuen Link', async ({ page }) => {
                await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
                await newPassword(page).fill('Kxq7-Lmw2!-lang');
                await page.getByRole('button', { name: 'Passwort speichern' }).click();
                await expect(page.getByRole('alert')).toContainText(
                    'abgelaufen oder wurde schon benutzt',
                );
                await expect(page.getByTestId('error-code')).toHaveText('Code: invalid_token');
                await expect(newPassword(page)).toHaveCount(0);
                await page.getByRole('link', { name: 'Neuen Link anfordern' }).click();
                await expect(page).toHaveURL(/passwort-vergessen$/);
            });
        });

        test.describe('Sperrliste (nur Server)', () => {
            const message =
                'Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.';
            test.use({ api: { [CONFIRM]: apiError(422, 'weak_password', message) } });

            test('Meldung des Servers am Feld, verschwindet beim Tippen', async ({ page }) => {
                await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
                await newPassword(page).fill('Sommer2026!!');
                await page.getByRole('button', { name: 'Passwort speichern' }).click();
                await expect(page.getByTestId('password-server-error')).toHaveText(message);
                await expect(newPassword(page)).toHaveAttribute('aria-invalid', 'true');
                await expect(newPassword(page)).toBeFocused();
                await newPassword(page).fill('Kxq7-Lmw2!-lang');
                await expect(page.getByTestId('password-server-error')).toHaveCount(0);
            });
        });

        test.describe('zu viele Versuche', () => {
            test.use({
                api: {
                    [CONFIRM]: apiError(429, 'too_many_attempts', 'x', { 'Retry-After': '90' }),
                },
            });

            test('nennt die Wartezeit, Formular bleibt', async ({ page }) => {
                await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
                await newPassword(page).fill('Kxq7-Lmw2!-lang');
                await page.getByRole('button', { name: 'Passwort speichern' }).click();
                await expect(page.getByRole('alert')).toContainText('in etwa 2 Minuten');
                await expect(newPassword(page)).toHaveValue('Kxq7-Lmw2!-lang');
            });
        });

        test.describe('keine Verbindung', () => {
            test.use({ api: { [CONFIRM]: 'abort' } });

            test('Eingabe bleibt erhalten', async ({ page }) => {
                await page.goto(`/firefighting/test/passwort-zuruecksetzen#token=${TOKEN}`);
                await newPassword(page).fill('Kxq7-Lmw2!-lang');
                await page.getByRole('button', { name: 'Passwort speichern' }).click();
                await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
                await expect(newPassword(page)).toHaveValue('Kxq7-Lmw2!-lang');
            });
        });
    });
});
