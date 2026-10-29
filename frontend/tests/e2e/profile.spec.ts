import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { CURRENT_SESSION_ONLY, sessionJson } from './support/profile';

/** 29.10.2026 14:30 in Berlin (Zeitzone in playwright.config.ts). */
const NOW = new Date('2026-10-29T13:30:00Z');
const ME = { status: 200, body: meBody('user') } as const;
const PROFILE = '/firefighting/test/profil';
/** Erfüllt die Regeln: ohne Teile von „Anna Beispiel“, anna@…, „test“. */
const NEW_PASSWORD = 'Löschwasser Brunnen 7';

function passwordRequests(requests: readonly MockRequest[]): unknown[] {
    return requests
        .filter((r) => r.method === 'POST' && r.path === 'api/v1/auth/password')
        .map((r) => r.body);
}

async function openPasswordForm(page: Page): Promise<void> {
    await page.goto(PROFILE);
    await page.getByRole('button', { name: 'Passwort ändern' }).click();
    await expect(page.locator('#current-password')).toBeFocused();
}

async function submitPassword(page: Page, current: string, next: string): Promise<void> {
    await page.locator('#current-password').fill(current);
    await page.locator('#new-password').fill(next);
    await page.getByTestId('password-submit').click();
}

function apiError(status: number, error: string, message: string): MockResponse {
    return { status, body: { error, message } };
}

test.describe('Profil: Anzeigemodus', () => {
    test.use({ api: { 'GET api/v1/auth/me': ME, ...CURRENT_SESSION_ONLY } });

    test('wirkt sofort, gilt für dieses Gerät und übersteht das Neuladen', async ({ page }) => {
        await page.emulateMedia({ colorScheme: 'light' });
        await page.goto(PROFILE);
        const group = page.getByRole('radiogroup', { name: 'Anzeigemodus' });
        const radio = (name: string) => group.getByRole('radio', { name: new RegExp(`^${name}`) });
        const html = page.locator('html');
        const hint = page.getByTestId('theme-hint');

        await expect(radio('System')).toBeChecked();
        await expect(hint).toHaveText(
            'Wird auf diesem Gerät gespeichert. Ihr Gerät ist gerade hell eingestellt.',
        );
        await page.emulateMedia({ colorScheme: 'dark' });
        await expect(hint).toContainText('Ihr Gerät ist gerade dunkel eingestellt.');

        await page.emulateMedia({ colorScheme: 'light' });
        await radio('Dunkel').check();
        await expect(html).toHaveAttribute('data-theme', 'dark');
        await expect(hint).toHaveText('Wird auf diesem Gerät gespeichert.');
        await page.reload();
        await expect(radio('Dunkel')).toBeChecked();
        await expect(html).toHaveAttribute('data-theme', 'dark');

        await radio('Hell').check();
        await expect(html).toHaveAttribute('data-theme', 'light');
        expect(await page.evaluate(() => window.localStorage.getItem('ff-theme'))).toBe('light');

        await radio('System').check();
        await expect(html).not.toHaveAttribute('data-theme');
        expect(await page.evaluate(() => window.localStorage.getItem('ff-theme'))).toBeNull();
    });
});

test.describe('Profil: Passwort ändern', () => {
    let changed = false;
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            // Vor der Änderung zwei Geräte, danach nur noch dieses mit neuer Sitzung.
            'GET api/v1/auth/sessions': (): MockResponse => ({
                status: 200,
                body: {
                    sessions: changed
                        ? [sessionJson(12, { current: true })]
                        : [sessionJson(3), sessionJson(8, { device_label: 'Safari auf iOS' })],
                },
            }),
            'POST api/v1/auth/password': (): MockResponse => {
                changed = true;
                return { status: 200, body: meBody('user') };
            },
        },
    });

    test.beforeEach(() => {
        changed = false;
    });

    test('prüft vor dem Senden, dann Erfolg und andere Geräte abgemeldet', async ({
        page,
        apiRequests,
    }) => {
        await openPasswordForm(page);
        await page.getByTestId('password-submit').click();
        await expect(page.getByTestId('current-password-error')).toHaveText(
            'Bitte geben Sie Ihr aktuelles Passwort ein.',
        );
        await expect(page.locator('#current-password')).toHaveAttribute('aria-invalid', 'true');

        // Teil des Namens: die Live-Prüfung verhindert das Senden.
        await submitPassword(page, 'altes Passwort 1', 'Anna Sommerregen 7');
        await expect(page.getByTestId('password-rules').locator('[data-state="error"]')).toHaveText(
            /Keine Teile von Name/,
        );
        await expect(page.locator('#new-password')).toBeFocused();
        expect(passwordRequests(apiRequests)).toHaveLength(0);

        await page.locator('#new-password').fill(NEW_PASSWORD);
        await page.getByTestId('password-submit').click();
        await expect(page.getByTestId('password-changed')).toHaveText(
            'Ihr Passwort wurde geändert. Alle anderen Geräte wurden abgemeldet.',
        );
        expect(passwordRequests(apiRequests)).toEqual([
            { current_password: 'altes Passwort 1', new_password: NEW_PASSWORD },
        ]);
        // Formular zu, Fokus auf dem Knopf; die Liste zeigt nur noch dieses Gerät.
        await expect(page.locator('#current-password')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Passwort ändern' })).toBeFocused();
        await expect(page.getByTestId('session')).toHaveCount(1);
        await expect(page.getByTestId('session')).toContainText('Dieses Gerät');
        await expect(page).toHaveURL(/\/profil$/);
    });

    test('Abbrechen leert das Formular', async ({ page }) => {
        await openPasswordForm(page);
        await page.locator('#current-password').fill('etwas');
        await page.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(page.getByRole('button', { name: 'Passwort ändern' })).toBeFocused();
        await page.getByRole('button', { name: 'Passwort ändern' }).click();
        await expect(page.locator('#current-password')).toHaveValue('');
    });
});

test.describe('Profil: Passwort falsch (403)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...CURRENT_SESSION_ONLY,
            'POST api/v1/auth/password': apiError(
                403,
                'invalid_current_password',
                'Das aktuelle Passwort ist falsch.',
            ),
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('Meldung am Feld, Feld geleert, bleibt angemeldet', async ({ page }) => {
        await openPasswordForm(page);
        await submitPassword(page, 'falsches Passwort', NEW_PASSWORD);
        await expect(page.getByTestId('current-password-error')).toHaveText(
            'Das aktuelle Passwort ist falsch.',
        );
        await expect(page.locator('#current-password')).toHaveValue('');
        await expect(page.locator('#current-password')).toBeFocused();
        await expect(page.locator('#new-password')).toHaveValue(NEW_PASSWORD);
        await expect(page).toHaveURL(/\/profil$/);
        // Tippen nimmt die Meldung weg.
        await page.locator('#current-password').fill('x');
        await expect(page.getByTestId('current-password-error')).toBeHidden();
    });
});

test.describe('Profil: neues Passwort abgelehnt (422)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...CURRENT_SESSION_ONLY,
            'POST api/v1/auth/password': apiError(
                422,
                'weak_password',
                'Dieses Passwort ist zu verbreitet. Bitte wählen Sie ein anderes.',
            ),
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('Meldung des Servers am neuen Passwort', async ({ page }) => {
        await openPasswordForm(page);
        await submitPassword(page, 'altes Passwort 1', NEW_PASSWORD);
        await expect(page.getByTestId('password-server-error')).toHaveText(
            'Dieses Passwort ist zu verbreitet. Bitte wählen Sie ein anderes.',
        );
        await expect(page.locator('#new-password')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('#new-password')).toBeFocused();
        await expect(page.locator('#current-password')).toHaveValue('altes Passwort 1');
        await page.locator('#new-password').fill(`${NEW_PASSWORD}!`);
        await expect(page.getByTestId('password-server-error')).toBeHidden();
    });
});

test.describe('Profil: zu viele Versuche (429)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...CURRENT_SESSION_ONLY,
            'POST api/v1/auth/password': {
                status: 429,
                body: { error: 'too_many_attempts', message: 'Zu viele Versuche.' },
                headers: { 'Retry-After': '30' },
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test('Knopf gesperrt mit Countdown bis Retry-After', async ({ page, apiRequests }) => {
        // Die 30 s werden vorgespult statt abgewartet.
        await page.clock.install({ time: NOW });
        await openPasswordForm(page);
        await submitPassword(page, 'altes Passwort 1', NEW_PASSWORD);
        await expect(page.getByRole('alert')).toContainText(
            'Zu viele Versuche. Bitte versuchen Sie es in 30 Sekunden erneut.',
        );
        const submit = page.getByTestId('password-submit');
        await expect(submit).toBeDisabled();
        await expect(submit).toHaveText('Wieder möglich in 0:30');
        await page.clock.runFor(31_000);
        await expect(submit).toBeEnabled();
        await expect(submit).toHaveText('Passwort speichern');
        expect(passwordRequests(apiRequests)).toHaveLength(1);
    });
});

test.describe('Profil: angemeldete Geräte', () => {
    let sessions: Record<string, unknown>[] = [];
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/auth/sessions': (): MockResponse => ({ status: 200, body: { sessions } }),
            'DELETE api/v1/auth/sessions/8': (): MockResponse => {
                sessions = sessions.filter((s) => s['id'] !== 8);
                return { status: 200, body: { deleted: true, current: false } };
            },
            'POST api/v1/auth/sessions/logout-others': (): MockResponse => {
                const ended = sessions.filter((s) => s['current'] !== true).length;
                sessions = sessions.filter((s) => s['current'] === true);
                return { status: 200, body: { ended_sessions: ended } };
            },
        },
    });

    test.beforeEach(async ({ page }) => {
        await page.clock.setFixedTime(NOW);
        // Wie vom Server: zuletzt benutzte zuerst (dieses Gerät schreibt seltener).
        sessions = [
            sessionJson(8, {
                device_label: 'Safari auf iOS',
                created_at: '2026-10-12T07:15:00Z',
                last_seen_at: '2026-10-29T13:25:00Z',
            }),
            sessionJson(3),
            sessionJson(9, {
                device_label: null,
                created_at: '2026-10-01T10:00:00Z',
                last_seen_at: '2026-10-28T16:30:00Z',
            }),
        ];
    });

    test('dieses Gerät zuerst, andere einzeln oder alle abmelden', async ({
        page,
        apiRequests,
    }) => {
        await page.goto(PROFILE);
        const items = page.getByTestId('session');
        await expect(items).toHaveCount(3);
        await expect(items.nth(0)).toContainText('Chrome auf Windows');
        await expect(items.nth(0)).toContainText('Dieses Gerät');
        await expect(items.getByTestId('session-time')).toHaveText([
            'Angemeldet heute 08:05',
            'Zuletzt aktiv vor 5 Min. · angemeldet 12.10.2026',
            'Zuletzt aktiv gestern 17:30 · angemeldet 01.10.2026',
        ]);
        await expect(items.nth(2)).toContainText('Unbekanntes Gerät');
        // Kein Knopf für dieses Gerät (dafür „Abmelden“ oben).
        await expect(items.nth(0).getByRole('button')).toHaveCount(0);

        await page.getByRole('button', { name: 'Safari auf iOS abmelden' }).click();
        await expect(page.getByTestId('sessions-message')).toHaveText(
            'Safari auf iOS wurde abgemeldet.',
        );
        await expect(items).toHaveCount(2);
        const deleted = apiRequests.find((r) => r.method === 'DELETE');
        expect(deleted?.path).toBe('api/v1/auth/sessions/8');
        expect(deleted?.body).toEqual({});

        // Rückfrage: Fokus auf „Abbrechen“, Abbrechen sendet nichts.
        const all = page.getByRole('button', { name: 'Alle anderen Geräte abmelden' });
        await all.click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('Alle anderen Geräte abmelden?');
        await expect(dialog.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await dialog.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(dialog).toBeHidden();
        expect(apiRequests.filter((r) => r.path.endsWith('logout-others'))).toHaveLength(0);

        await all.click();
        await dialog.getByRole('button', { name: 'Abmelden', exact: true }).click();
        await expect(page.getByTestId('sessions-message')).toHaveText('1 Gerät wurde abgemeldet.');
        await expect(items).toHaveCount(1);
        await expect(all).toBeHidden();
        expect(apiRequests.filter((r) => r.path.endsWith('logout-others'))).toHaveLength(1);
        await expect(page).toHaveURL(/\/profil$/);
    });

    test('Handy-Breite: kein seitliches Überstehen, Bedienelemente mindestens 44 px', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto(PROFILE);
        await expect(page.getByTestId('session')).toHaveCount(3);
        await page.getByRole('button', { name: 'Passwort ändern' }).click();
        await expect(page.locator('#current-password')).toBeVisible();
        // Wie in booking.spec.ts: nicht über clientWidth (Scrollleiste des emulierten iPhones).
        const overflow = await page.evaluate(() => {
            const width = document.documentElement.getBoundingClientRect().right;
            return [...document.querySelectorAll('body *')]
                .filter((element) => element.getBoundingClientRect().right > width + 0.5)
                .map((element) => `${element.tagName}.${element.className}`);
        });
        expect(overflow).toEqual([]);
        const main = page.getByRole('main');
        const controls = [
            ...(await main.getByRole('button').all()),
            ...(await main.locator('label:has(input[type="radio"])').all()),
        ];
        for (const control of controls) {
            const box = await control.boundingBox();
            // WebKit misst wegen seiner Skalierung z. B. 43,99 statt 44.
            expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
        }
    });
});

test.describe('Profil: Geräteliste nicht ladbar', () => {
    let calls = 0;
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/auth/sessions': (): MockResponse => {
                calls += 1;
                return calls === 1
                    ? apiError(503, 'database_unavailable', 'Die Datenbank ist nicht erreichbar.')
                    : { status: 200, body: { sessions: [sessionJson(3)] } };
            },
        },
        allowedConsoleError: HTTP_ERROR_CONSOLE,
    });

    test.beforeEach(() => {
        calls = 0;
    });

    test('Fehler mit erneutem Versuch', async ({ page }) => {
        await page.goto(PROFILE);
        await expect(page.getByRole('alert')).toContainText('database_unavailable');
        await page.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(page.getByTestId('session')).toHaveCount(1);
    });
});
