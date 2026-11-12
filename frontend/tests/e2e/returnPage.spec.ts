import type { Page } from '@playwright/test';
import { expect, HTTP_ERROR_CONSOLE, test, type MockRequest, type MockResponse } from './fixtures';
import { horizontalOverflow, smallTargets } from './support/layout';

/**
 * Rückgabe ohne Anmeldung (Phase 2, P2.6): Der QR-Code des Aushangs führt zu `rueckgabe#schluessel=…`.
 * Die Seite braucht keine Sitzung (kein `auth/me`), liest den Schlüssel aus dem Fragment und sendet nur
 * an `returns/check` und `returns`. Der Schlüssel steht in keiner angefragten Adresse.
 */

const KEY = 'Sk1_aus-dem-Aushang_0123456789abcdefghijklmnop';

const UNKNOWN =
    'Diese Nummer kennen wir nicht oder die Ausrüstung ist nicht verliehen. Bitte schreiben Sie sie so wie auf dem Etikett, z. B. HRT 14.';

function invalid(message: string): MockResponse {
    return { status: 400, body: { error: 'invalid_request', message } };
}

/** Alle Adressen, die die Seite anfragt (der Schlüssel darf in keiner stehen). */
function recordUrls(page: Page): string[] {
    const urls: string[] = [];
    page.on('request', (request) => urls.push(request.url()));
    return urls;
}

const checks: unknown[] = [];
const returns: unknown[] = [];

function answer(request: MockRequest): MockResponse {
    checks.push(request.body);
    const { code } = request.body as { code: string };
    return code === 'HRT 16'
        ? {
              status: 200,
              body: { identifier: 'HRT 16', device_type: 'Atemschutzgerät', location: 'Rückgabe' },
          }
        : code === 'HRT 14'
          ? {
                status: 400,
                body: {
                    error: 'invalid_request',
                    message: 'HRT 14 wurde bereits zurückgegeben. Sie müssen nichts weiter tun.',
                    details: { identifier: 'HRT 14', returned_at: '2026-11-12T10:02:00Z' },
                },
            }
          : invalid(UNKNOWN);
}

function give(request: MockRequest): MockResponse {
    returns.push(request.body);
    return {
        status: 200,
        body: {
            identifier: 'HRT 16',
            device_type: 'Atemschutzgerät',
            location: 'Rückgabe',
            status_label: 'defekt',
            returned_at: new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
        },
    };
}

test.beforeEach(() => {
    checks.length = 0;
    returns.length = 0;
});

test.describe('Mit Schlüssel', () => {
    test.use({
        allowedConsoleError: HTTP_ERROR_CONSOLE,
        api: {
            'POST api/v1/returns/check': answer,
            'POST api/v1/returns': give,
        },
    });

    for (const width of [390, 768, 1024]) {
        test(`Bildliche Anleitung (Runde 14): vier Schritte bei ${String(width)} px ohne Überstand`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
            await page.getByTestId('return-steps').locator('summary').click();
            const steps = page.getByTestId('return-page').getByTestId('how-to-step');
            await expect(steps).toHaveCount(4);
            await expect(steps.first()).toContainText('QR-Code scannen');
            await expect(steps.nth(3)).toContainText('Ablegen');
            await expect(steps.first().getByRole('img')).toBeVisible();
            expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        });
    }

    test('Nummer prüfen, Name und Zustand angeben, zurückgeben, weitere Ausrüstung', async ({
        page,
    }) => {
        const urls = recordUrls(page);
        await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Ausrüstung zurückgeben' }),
        ).toBeVisible();
        await expect(page).not.toHaveURL(/schluessel/);

        await page.getByTestId('return-code').fill('HRT 99');
        await page.getByTestId('return-check').click();
        await expect(page.getByTestId('return-problem')).toHaveText(UNKNOWN);
        await expect(page.getByTestId('return-name')).toHaveCount(0);

        await page.getByTestId('return-code').fill('HRT 16');
        await page.getByTestId('return-code').press('Enter');
        await expect(page.getByTestId('return-found')).toContainText('HRT 16 · Atemschutzgerät');
        await expect(page.getByTestId('return-found')).toContainText('„Rückgabe“');

        await page.getByTestId('return-submit').click();
        await expect(page.getByTestId('return-problem')).toHaveText(
            'Bitte geben Sie Ihren Namen ein.',
        );
        await page.getByTestId('return-name').fill('Herr Maier');
        await page.getByLabel('Defekt').check();
        await page.getByLabel('Hinweis (optional)').fill('Sprung im Visier');
        await page.getByTestId('return-submit').click();

        // U1: Beleg mit Nummer, Zustand, Hinweis, Zeit und dem Satz, dass nichts weiter zu tun ist.
        const receipt = page.getByTestId('return-receipt');
        await expect(page.getByTestId('return-done')).toContainText('Ihre Rückgabe ist angekommen');
        await expect(receipt).toContainText('HRT 16 (Atemschutzgerät)');
        await expect(receipt).toContainText('Defekt');
        await expect(receipt).toContainText('Sprung im Visier');
        await expect(receipt).toContainText('heute');
        await expect(receipt).toContainText('„Rückgabe“');
        await expect(page.getByTestId('return-done')).toContainText('Sie müssen nichts weiter tun');
        expect(returns).toEqual([
            {
                key: KEY,
                code: 'HRT 16',
                name: 'Herr Maier',
                condition: 'defective',
                comment: 'Sprung im Visier',
            },
        ]);
        expect(checks.at(-1)).toEqual({ key: KEY, code: 'HRT 16' });

        await page.getByTestId('return-next').click();
        // U22: der Fokus steht wieder im Nummernfeld.
        await expect(page.getByTestId('return-code')).toBeFocused();
        await expect(page.getByTestId('return-code')).toHaveValue('');
        await expect(page.getByTestId('return-found')).toHaveCount(0);
        expect(urls.filter((url) => url.includes(KEY))).toEqual([]);
        expect(urls.some((url) => url.includes('auth/me'))).toBe(false);
    });

    test('U1: schon zurückgegebene Nummer sagt das mit Uhrzeit, ein Tippfehler etwas anderes', async ({
        page,
    }) => {
        await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
        await page.getByTestId('return-code').fill('HRT 14');
        await page.getByTestId('return-check').click();
        await expect(page.getByTestId('return-already')).toContainText(
            'HRT 14 wurde bereits zurückgegeben',
        );
        await expect(page.getByTestId('return-already')).toContainText(
            'Sie müssen nichts weiter tun',
        );
        await expect(page.getByTestId('return-problem')).toHaveCount(0);
        await page.getByTestId('return-code').fill('HRT 99');
        await expect(page.getByTestId('return-already')).toHaveCount(0);
        await page.getByTestId('return-check').click();
        await expect(page.getByTestId('return-problem')).toHaveText(UNKNOWN);
        await expect(page.getByTestId('return-already')).toHaveCount(0);
    });

    test('U22: Formular steht vor den Bildern, Knopf „Nummer suchen“, Zustand mit großen Zielen', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 700 });
        await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
        const form = await page.getByTestId('return-code').boundingBox();
        const steps = await page.getByTestId('return-steps').boundingBox();
        expect(form?.y ?? Infinity).toBeLessThan(steps?.y ?? 0);
        // Das Formular samt Knopf liegt ohne Scrollen im Bild.
        const button = page.getByRole('button', { name: 'Nummer suchen' });
        await expect(button).toBeInViewport();
        await expect(page.getByRole('button', { name: 'Prüfen', exact: true })).toHaveCount(0);
        await page.getByTestId('return-code').fill('HRT 16');
        await button.click();
        await expect(page.getByTestId('return-name')).toBeVisible();
        // U3: jede Zustandsfläche (Zeile) ist mindestens 44 px hoch und breit.
        expect(await smallTargets(page)).toEqual([]);
    });

    test('übersteht ein Neuladen (Schlüssel im Tab gemerkt)', async ({ page }) => {
        await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
        await expect(page.getByTestId('return-code')).toBeVisible();
        await page.reload();
        await expect(page.getByTestId('return-code')).toBeVisible();
        await expect(page.getByTestId('return-key-missing')).toHaveCount(0);
    });

    test('Handy-Breite: kein waagerechter Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 360, height: 740 });
        await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
        await page.getByTestId('return-code').fill('HRT 16');
        await page.getByTestId('return-check').click();
        await expect(page.getByTestId('return-name')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
    });
});

test.describe('Ohne oder mit ungültigem Schlüssel', () => {
    test('ohne Schlüssel: Hinweis, kein Formular', async ({ page }) => {
        await page.goto('/firefighting/test/rueckgabe');
        await expect(page.getByTestId('return-key-missing')).toContainText(
            'Dieser Link ist unvollständig.',
        );
        await expect(page.getByTestId('return-code')).toHaveCount(0);
    });

    test.describe('Schlüssel widerrufen', () => {
        test.use({
            allowedConsoleError: HTTP_ERROR_CONSOLE,
            api: {
                'POST api/v1/returns/check': () =>
                    invalid(
                        'Dieser Rückgabe-Link gilt nicht (mehr). Bitte den aktuellen Aushang verwenden.',
                    ),
            },
        });

        test('der Server lehnt ab: Formular weg, Meldung bleibt', async ({ page }) => {
            await page.goto(`/firefighting/test/rueckgabe#schluessel=${KEY}`);
            await page.getByTestId('return-code').fill('HRT 16');
            await page.getByTestId('return-check').click();
            await expect(page.getByTestId('return-key-missing')).toContainText(
                'Dieser Rückgabe-Link gilt nicht (mehr).',
            );
            await expect(page.getByTestId('return-code')).toHaveCount(0);
        });
    });
});
