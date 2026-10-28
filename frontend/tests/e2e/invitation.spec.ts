import type { Page } from '@playwright/test';
import {
    expect,
    NETWORK_ERROR_CONSOLE,
    test,
    type MockResponse,
    type TenantResponse,
} from './fixtures';

const ACCEPT = 'POST api/v1/auth/invitation/accept';
const TOKEN = 'Inv1te_aus-der-Mail_0123456789abcdefghijklmnopq';
const ACCEPTED_MESSAGE =
    'Ihr Konto ist eingerichtet. Bitte melden Sie sich mit Ihrer E-Mail-Adresse und dem neuen Passwort an.';
const PAGE = `/firefighting/test/einladung-annehmen#token=${TOKEN}`;

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

const field = {
    name: (page: Page) => page.getByLabel('Name', { exact: true }),
    password: (page: Page) => page.getByLabel('Neues Passwort', { exact: true }),
};

async function fillForm(page: Page): Promise<void> {
    await field.name(page).fill('Bernd Brandmeister');
    await field.password(page).fill('Kxq7-Lmw2!-lang');
}

function submit(page: Page) {
    return page.getByRole('button', { name: 'Konto einrichten' }).click();
}

test.describe('Einladung annehmen', () => {
    test.describe('erfolgreich', () => {
        test.use({ api: { [ACCEPT]: { status: 200, body: { message: ACCEPTED_MESSAGE } } } });

        test('Token aus dem Fragment, per POST, danach Anmeldung mit Hinweis', async ({
            page,
            apiRequests,
        }) => {
            const urls = recordUrls(page);
            await page.goto(PAGE);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Einladung annehmen');
            await expect(page).toHaveURL(/\/firefighting\/test\/einladung-annehmen$/);
            await fillForm(page);
            await submit(page);

            await expect(page).toHaveURL(/\/firefighting\/test\/anmelden$/);
            await expect(page.getByTestId('login-notice')).toHaveText(ACCEPTED_MESSAGE);
            expect(apiRequests[0]?.body).toEqual({
                token: TOKEN,
                name: 'Bernd Brandmeister',
                password: 'Kxq7-Lmw2!-lang',
            });
            expect(urls.filter((url) => url.includes(TOKEN))).toEqual([]);
        });

        test('Pflichtfeld Name und Live-Prüfung: keine Anfrage', async ({ page, apiRequests }) => {
            await page.goto(PAGE);
            await submit(page);
            await expect(page.getByText('Bitte geben Sie Ihren Namen ein.')).toBeVisible();
            await expect(field.name(page)).toBeFocused();

            await field.name(page).fill('Bernd Brandmeister');
            const personal = page
                .getByTestId('password-rules')
                .getByRole('listitem')
                .filter({ hasText: 'Keine Teile von Name' });
            await field.password(page).fill('Brandmeister-Passwort');
            await expect(personal).toHaveAttribute('data-state', 'error');
            await submit(page);
            await expect(field.password(page)).toBeFocused();
            expect(apiRequests).toEqual([]);
        });

        test('Formular für Passwort-Manager und Handys', async ({ page }) => {
            await page.goto(PAGE);
            await expect(field.name(page)).toHaveAttribute('autocomplete', 'name');
            await expect(field.password(page)).toHaveAttribute('autocomplete', 'new-password');
            await page.getByRole('button', { name: 'Passwort anzeigen' }).click();
            await expect(field.password(page)).toHaveAttribute('type', 'text');
            const box = await page.getByRole('button', { name: 'Konto einrichten' }).boundingBox();
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        });
    });

    test('ohne Token: Hinweis, kein Formular', async ({ page }) => {
        await page.goto('/firefighting/test/einladung-annehmen');
        await expect(page.getByRole('alert')).toContainText('Link ist unvollständig');
        await expect(page.getByRole('alert')).toContainText('Super-Admin');
        await expect(field.name(page)).toHaveCount(0);
    });

    test.describe('Fehler', () => {
        test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

        test.describe('Einladung abgelaufen oder benutzt', () => {
            test.use({ api: { [ACCEPT]: apiError(400, 'invalid_token') } });

            test('Meldung mit Weg zu einer neuen Einladung, Formular weg', async ({ page }) => {
                await page.goto(PAGE);
                await fillForm(page);
                await submit(page);
                await expect(page.getByRole('alert')).toContainText(
                    'Bitte fordern Sie beim Super-Admin eine neue Einladung an.',
                );
                await expect(page.getByTestId('error-code')).toHaveText('Code: invalid_token');
                await expect(field.name(page)).toHaveCount(0);
            });
        });

        test.describe('Sperrliste (nur Server)', () => {
            test.use({
                api: {
                    [ACCEPT]: apiError(
                        422,
                        'weak_password',
                        'Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.',
                    ),
                },
            });

            test('Meldung des Servers am Passwort, Eingaben bleiben', async ({ page }) => {
                await page.goto(PAGE);
                await fillForm(page);
                await submit(page);
                await expect(page.getByTestId('password-server-error')).toContainText(
                    'zu verbreitet',
                );
                await expect(field.password(page)).toBeFocused();
                await expect(field.name(page)).toHaveValue('Bernd Brandmeister');
            });
        });

        test.describe('Name abgelehnt', () => {
            test.use({
                api: {
                    [ACCEPT]: apiError(
                        400,
                        'invalid_request',
                        'Der Name darf höchstens 100 Zeichen lang sein (angegeben: 101).',
                    ),
                },
            });

            test('Meldung am Feld Name', async ({ page }) => {
                await page.goto(PAGE);
                await fillForm(page);
                await submit(page);
                await expect(page.getByText('höchstens 100 Zeichen')).toBeVisible();
                await expect(field.name(page)).toBeFocused();
            });
        });

        test.describe('zu viele Versuche', () => {
            test.use({
                api: {
                    [ACCEPT]: apiError(429, 'too_many_attempts', 'x', { 'Retry-After': '900' }),
                },
            });

            test('nennt die Wartezeit, Formular bleibt', async ({ page }) => {
                await page.goto(PAGE);
                await fillForm(page);
                await submit(page);
                await expect(page.getByRole('alert')).toContainText('in etwa 15 Minuten');
                await expect(field.name(page)).toHaveValue('Bernd Brandmeister');
            });
        });

        test.describe('keine Verbindung', () => {
            test.use({ api: { [ACCEPT]: 'abort' } });

            test('Eingaben bleiben erhalten', async ({ page }) => {
                await page.goto(PAGE);
                await fillForm(page);
                await submit(page);
                await expect(page.getByRole('alert')).toContainText('Keine Verbindung');
                await expect(field.password(page)).toHaveValue('Kxq7-Lmw2!-lang');
            });
        });

        test.describe('gesperrte Stadt', () => {
            const inactive: TenantResponse = {
                status: 403,
                body: { error: 'tenant_inactive', message: 'Gesperrt.' },
            };
            test.use({ tenant: inactive });

            test('kein Formular', async ({ page }) => {
                await page.goto(PAGE);
                await expect(page.getByRole('alert').first()).toContainText('gesperrt');
                await expect(field.name(page)).toHaveCount(0);
            });
        });
    });

    test('unter service gibt es die Seite nicht', async ({ page }) => {
        await page.goto(`/firefighting/service/einladung-annehmen#token=${TOKEN}`);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Seite nicht gefunden' }),
        ).toBeVisible();
    });
});
