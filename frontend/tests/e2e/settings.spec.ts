import { inflateSync } from 'node:zlib';
import type { Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { horizontalOverflow } from './support/layout';
import { delegate } from './support/masterData';
import { CURRENT_SESSION_ONLY } from './support/profile';

/**
 * Einstellungen der Admin-Konsole (`einstellungen`, Mockup „Einstellungen“): Anzeigemodus,
 * Erscheinungsbild (Akzentfarbe mit Kontrastprüfung und Vorschau, Logo, das die App auf 512 × 512
 * PNG bringt), Benachrichtigung „Log“, Passwort und angemeldete Geräte. Der simulierte Server merkt
 * sich Farbe, Logo und Benachrichtigung wie TenantAppearanceRepository bzw. UserRepository.
 */

const ME = { status: 200, body: meBody('admin', 'Thomas Keller') } as const;
const SETTINGS = '/firefighting/test/einstellungen';

/** Ein PNG wie es der Browser aus der Canvas schreibt (8 Bit RGBA), entfiltert. */
interface DecodedPng {
    readonly width: number;
    readonly height: number;
    readonly colorType: number;
    /** RGBA des Pixels (x, y). */
    pixel(x: number, y: number): readonly number[];
}

function paeth(a: number, b: number, c: number): number {
    const p = a + b - c;
    const pa = Math.abs(p - a);
    const pb = Math.abs(p - b);
    const pc = Math.abs(p - c);
    if (pa <= pb && pa <= pc) {
        return a;
    }
    return pb <= pc ? b : c;
}

function decodePng(base64: string): DecodedPng {
    const bytes = Buffer.from(base64, 'base64');
    expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a');
    let offset = 8;
    let width = 0;
    let height = 0;
    let colorType = -1;
    const data: Buffer[] = [];
    while (offset < bytes.length) {
        const length = bytes.readUInt32BE(offset);
        const type = bytes.toString('latin1', offset + 4, offset + 8);
        const chunk = bytes.subarray(offset + 8, offset + 8 + length);
        if (type === 'IHDR') {
            width = chunk.readUInt32BE(0);
            height = chunk.readUInt32BE(4);
            colorType = chunk.readUInt8(9);
        } else if (type === 'IDAT') {
            data.push(chunk);
        }
        offset += 12 + length;
    }
    const raw = inflateSync(Buffer.concat(data));
    const stride = width * 4;
    const pixels = Buffer.alloc(stride * height);
    for (let y = 0; y < height; y++) {
        const filter = raw[y * (stride + 1)] ?? 0;
        for (let i = 0; i < stride; i++) {
            const value = raw[y * (stride + 1) + 1 + i] ?? 0;
            const left = i >= 4 ? (pixels[y * stride + i - 4] ?? 0) : 0;
            const up = y > 0 ? (pixels[(y - 1) * stride + i] ?? 0) : 0;
            const upLeft = y > 0 && i >= 4 ? (pixels[(y - 1) * stride + i - 4] ?? 0) : 0;
            const predicted = [0, left, up, Math.floor((left + up) / 2), paeth(left, up, upLeft)][
                filter
            ];
            pixels[y * stride + i] = (value + (predicted ?? 0)) & 0xff;
        }
    }
    return {
        width,
        height,
        colorType,
        pixel: (x, y) => [...pixels.subarray(y * stride + x * 4, y * stride + x * 4 + 4)],
    };
}

/** Stadt wie AppearanceJson::tenant (Logo-Adressen mit Versionskennung). */
function tenantBody(accent: string | null, logoVersion: string | null): Record<string, unknown> {
    const urls =
        logoVersion === null
            ? null
            : Object.fromEntries(
                  ['512', '192', '180'].map((size) => [
                      size,
                      `/firefighting/test/api/v1/tenant/logo/${size}?v=${logoVersion}`,
                  ]),
              );
    return {
        slug: 'test',
        name: 'Feuerwehr Test',
        accent_color: accent ?? '#b71c1c',
        accent_color_is_default: accent === null,
        logo_url: urls?.['512'] ?? null,
        logo_urls: urls,
    };
}

type Respond = (request: MockRequest) => MockResponse | null;

/** Erscheinungsbild und Benachrichtigung; `respond` ersetzt einzelne Antworten (Fehler). */
function settingsServer(respond: Respond = () => null) {
    let accent: string | null = null;
    let logo: number | null = null;
    let deviceLog = false;
    const tenant = (): MockResponse => ({
        status: 200,
        body: tenantBody(accent, logo === null ? null : `v${String(logo)}`),
    });
    const handlers: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/tenant': tenant,
        'POST api/v1/tenant/appearance': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            accent = (request.body as { accent_color: string | null }).accent_color;
            return tenant();
        },
        'POST api/v1/tenant/logo': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const png = decodePng((request.body as { file: string }).file);
            expect([png.width, png.height]).toEqual([512, 512]);
            logo = (logo ?? 0) + 1;
            return tenant();
        },
        'DELETE api/v1/tenant/logo': () => {
            logo = null;
            return tenant();
        },
        'GET api/v1/auth/notifications': () => ({
            status: 200,
            body: { notifications: { device_log: deviceLog } },
        }),
        'POST api/v1/auth/notifications': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            deviceLog = (request.body as { device_log: boolean }).device_log;
            return { status: 200, body: { notifications: { device_log: deviceLog } } };
        },
    };
    return handlers;
}

let server = settingsServer();

function bodies(requests: readonly MockRequest[], key: string): unknown[] {
    return requests.filter((r) => `${r.method} ${r.path}` === key).map((r) => r.body);
}

function swatch(page: Page, name: string) {
    return page.getByRole('radiogroup', { name: 'Akzentfarbe' }).getByRole('radio', { name });
}

/** Farbkreis antippen (das Optionsfeld darin ist nur für Bildschirmleser sichtbar). */
async function pickSwatch(page: Page, name: string): Promise<void> {
    await page
        .locator('label.swatch')
        .filter({ has: page.getByRole('radio', { name, exact: true }) })
        .click();
}

/** Bild als Datei über die Dateiauswahl der Seite (wie ein Admin am PC). */
async function chooseLogo(page: Page, name: string, mimeType: string, content: string | Buffer) {
    const chooser = page.waitForEvent('filechooser');
    await page.getByRole('button', { name: /Logo hochladen/ }).click();
    await (await chooser).setFiles({ name, mimeType, buffer: Buffer.from(content) });
}

/** 200 × 100 px, ganz rot: in 512 × 512 oben und unten durchsichtiger Rand. */
const WIDE_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100">' +
    '<rect width="200" height="100" fill="#c00000"/></svg>';

test.describe('Einstellungen', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            ...ADMIN_OVERVIEW,
            ...CURRENT_SESSION_ONLY,
            ...delegate(() => server),
        },
    });

    test.beforeEach(() => {
        server = settingsServer();
    });

    test('über das Menü: alle Abschnitte wie im Mockup', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/dashboard');
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await nav.getByRole('link', { name: 'Einstellungen' }).click();
        await expect(page).toHaveURL(/\/einstellungen$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Einstellungen' })).toBeVisible();
        await expect(nav.getByRole('link', { name: 'Einstellungen' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await expect(page.getByRole('heading', { level: 2 })).toHaveText([
            'Anzeigemodus',
            'Erscheinungsbild der Feuerwehr',
            'Benachrichtigung',
            'Passwort',
            'Angemeldete Geräte',
        ]);
        await expect(page.getByRole('radiogroup', { name: 'Anzeigemodus' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Passwort ändern' })).toBeVisible();
        await expect(page.getByTestId('session')).toContainText('Dieses Gerät');
        await expect(swatch(page, 'Feuerwehrrot (Standard)')).toBeChecked();
        await expect(page.getByLabel(/Farbe als Hex-Wert/)).toHaveValue('#B71C1C');
        await expect(page.getByTestId('logo-default')).toBeVisible();
    });

    test('Akzentfarbe: Vorschau beim Wählen, gilt erst nach dem Speichern', async ({
        page,
        apiRequests,
    }) => {
        await page.goto(SETTINGS);
        const preview = page.getByTestId('appearance-preview').locator('.preview-bar');
        await expect(preview).toHaveCSS('background-color', 'rgb(183, 28, 28)');

        await pickSwatch(page, 'Dunkelblau');
        await expect(page.getByLabel(/Farbe als Hex-Wert/)).toHaveValue('#1D3557');
        await expect(page.getByTestId('accent-check')).toHaveText(
            '✓ Weiße Schrift auf dieser Farbe ist gut lesbar (Kontrast 12,35:1).',
        );
        await expect(preview).toHaveCSS('background-color', 'rgb(29, 53, 87)');
        // Die App selbst bleibt in der gespeicherten Farbe.
        expect(
            await page.evaluate(() => document.documentElement.style.getPropertyValue('--accent')),
        ).toBe('');

        await page.getByRole('button', { name: 'Farbe speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Akzentfarbe gespeichert. Sie gilt ab jetzt für alle Benutzer der Stadt.',
        );
        expect(
            await page.evaluate(() => document.documentElement.style.getPropertyValue('--accent')),
        ).toBe('#1d3557');
        await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute(
            'content',
            '#1d3557',
        );
        await expect(page.getByRole('button', { name: 'Farbe speichern' })).toHaveCount(0);

        // Eigene Farbe: zu hell wird beim Tippen abgelehnt, Kurzform wird ergänzt.
        const field = page.getByLabel(/Farbe als Hex-Wert/);
        await field.fill('#e8b44c');
        await expect(field).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByTestId('accent-check')).toHaveText(
            '✕ Zu hell: Weiße Schrift auf #E8B44C erreicht nur einen Kontrast von 1,89:1; ' +
                'nötig sind mindestens 4,5:1. Bitte eine dunklere Farbe wählen.',
        );
        await expect(page.getByRole('button', { name: 'Farbe speichern' })).toBeDisabled();
        await field.fill('#a33');
        await expect(field).toHaveAttribute('aria-invalid', 'false');
        await field.press('Enter');
        await expect(field).toHaveValue('#AA3333');
        await expect(page.getByRole('button', { name: 'Farbe speichern' })).toHaveCount(0);

        await pickSwatch(page, 'Feuerwehrrot (Standard)');
        await page.getByRole('button', { name: 'Farbe speichern' }).click();
        await expect(page.getByRole('button', { name: 'Farbe speichern' })).toHaveCount(0);
        expect(
            await page.evaluate(() => document.documentElement.style.getPropertyValue('--accent')),
        ).toBe('');
        expect(bodies(apiRequests, 'POST api/v1/tenant/appearance')).toEqual([
            { accent_color: '#1d3557' },
            { accent_color: '#aa3333' },
            { accent_color: null },
        ]);
    });

    test('Akzentfarbe: Verwerfen stellt den gespeicherten Stand her', async ({ page }) => {
        await page.goto(SETTINGS);
        await pickSwatch(page, 'Grün');
        await page.getByRole('button', { name: 'Verwerfen' }).click();
        await expect(swatch(page, 'Feuerwehrrot (Standard)')).toBeChecked();
        await expect(page.getByLabel(/Farbe als Hex-Wert/)).toHaveValue('#B71C1C');
    });

    test('Logo: Bild auf 512 × 512 gebracht, gespeichert, überall gezeigt und entfernt', async ({
        page,
        apiRequests,
    }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(SETTINGS);
        await chooseLogo(page, 'wappen.svg', 'image/svg+xml', WIDE_SVG);
        await expect(page.getByTestId('logo-pending')).toBeVisible();
        await expect(page.getByTestId('logo-image')).toHaveAttribute('src', /^blob:/);
        await expect(page.getByTestId('logo-image')).toHaveAttribute(
            'alt',
            'Neues Logo (noch nicht gespeichert)',
        );
        // Noch nichts gesendet, das Menü zeigt weiter das Standard-Symbol.
        expect(bodies(apiRequests, 'POST api/v1/tenant/logo')).toHaveLength(0);

        await page.getByRole('button', { name: 'Logo speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Logo gespeichert. Es erscheint ab jetzt im Menü, auf der Anmeldeseite und als App-Symbol.',
        );
        const [sent] = bodies(apiRequests, 'POST api/v1/tenant/logo') as { file: string }[];
        const png = decodePng(sent?.file ?? '');
        expect([png.width, png.height, png.colorType]).toEqual([512, 512, 6]);
        // 200 × 100 → 480 × 240 mittig: oben und unten durchsichtig, in der Mitte rot.
        expect(png.pixel(256, 10)[3]).toBe(0);
        expect(png.pixel(256, 130)[3]).toBe(0);
        expect(png.pixel(256, 500)[3]).toBe(0);
        expect(png.pixel(256, 256)).toEqual([192, 0, 0, 255]);
        expect(png.pixel(20, 256)).toEqual([192, 0, 0, 255]);
        expect(png.pixel(5, 256)[3]).toBe(0);

        await expect(page.getByTestId('logo-image')).toHaveAttribute(
            'src',
            '/firefighting/test/api/v1/tenant/logo/512?v=v1',
        );
        await expect(
            page.getByRole('navigation', { name: 'Hauptmenü' }).locator('img.menu-logo'),
        ).toHaveAttribute('src', '/firefighting/test/api/v1/tenant/logo/192?v=v1');
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute(
            'href',
            '/firefighting/test/api/v1/tenant/logo/192?v=v1',
        );

        await page.getByRole('button', { name: 'Logo entfernen …' }).click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toContainText('Logo entfernen?');
        await dialog.getByRole('button', { name: 'Logo entfernen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Logo entfernt. Die App zeigt wieder das Standard-Symbol.',
        );
        await expect(page.getByTestId('logo-default')).toBeVisible();
        await expect(page.locator('link[rel="icon"]')).toHaveAttribute(
            'href',
            'api/v1/app-icon/192',
        );
        expect(bodies(apiRequests, 'DELETE api/v1/tenant/logo')).toEqual([{}]);
    });

    test('Logo: Verwerfen und unlesbare Datei', async ({ page, apiRequests }) => {
        await page.goto(SETTINGS);
        await chooseLogo(page, 'wappen.svg', 'image/svg+xml', WIDE_SVG);
        await page.getByRole('button', { name: 'Verwerfen' }).click();
        await expect(page.getByTestId('logo-default')).toBeVisible();
        await expect(page.getByTestId('logo-pending')).toHaveCount(0);

        await chooseLogo(page, 'logo.png', 'image/png', 'kein Bild');
        await expect(page.getByTestId('logo-problem')).toHaveText(
            'Die Datei konnte nicht als Bild gelesen werden. Bitte ein PNG, JPG oder SVG wählen.',
        );
        await expect(page.getByRole('button', { name: 'Logo speichern' })).toHaveCount(0);
        expect(bodies(apiRequests, 'POST api/v1/tenant/logo')).toHaveLength(0);
    });

    test('Benachrichtigung „Log“ wirkt sofort', async ({ page, apiRequests }) => {
        await page.goto(SETTINGS);
        const toggle = page.getByRole('checkbox', { name: /Mail bei jeder Änderung an Geräten/ });
        await expect(toggle).toBeEnabled();
        await expect(toggle).not.toBeChecked();
        await expect(page.locator('#notification-hint')).toContainText('an anna@example.org');

        await toggle.check();
        await expect(page.getByRole('status').filter({ hasText: 'Gespeichert' })).toHaveText(
            'Gespeichert: Sie erhalten ab jetzt eine Mail bei jeder Änderung an Geräten.',
        );
        await page.reload();
        await expect(toggle).toBeChecked();
        await toggle.uncheck();
        await expect(page.getByRole('status').filter({ hasText: 'Gespeichert' })).toHaveText(
            'Gespeichert: Sie erhalten keine Mails zu Änderungen an Geräten mehr.',
        );
        expect(bodies(apiRequests, 'POST api/v1/auth/notifications')).toEqual([
            { device_log: true },
            { device_log: false },
        ]);
    });

    test('Handy: eine Spalte ohne waagerechten Überstand, Einstellungen im Menü', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/firefighting/test/dashboard');
        await page.getByRole('button', { name: 'Menü öffnen' }).click();
        await page.getByTestId('menu-drawer').getByRole('link', { name: 'Einstellungen' }).click();
        await expect(page.getByRole('heading', { level: 1, name: 'Einstellungen' })).toBeVisible();
        await expect(page.getByTestId('appearance-preview')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
    });
});

test.describe('Einstellungen: Fehler des Servers', () => {
    test.use({
        allowedConsoleError: HTTP_ERROR_CONSOLE,
        api: {
            'GET api/v1/auth/me': ME,
            ...ADMIN_OVERVIEW,
            ...CURRENT_SESSION_ONLY,
            ...delegate(() => server),
        },
    });

    test.beforeEach(() => {
        server = settingsServer((request) =>
            request.method === 'POST'
                ? {
                      status: 500,
                      body: { error: 'internal_error', message: 'Interner Fehler.' },
                  }
                : null,
        );
    });

    test('Farbe und Benachrichtigung bleiben beim bisherigen Stand', async ({ page }) => {
        await page.goto(SETTINGS);
        await pickSwatch(page, 'Violett');
        await page.getByRole('button', { name: 'Farbe speichern' }).click();
        await expect(page.getByRole('alert').first()).toContainText('internal_error');
        await expect(page.getByLabel(/Farbe als Hex-Wert/)).toHaveValue('#4B3B7A');
        await expect(page.getByRole('button', { name: 'Farbe speichern' })).toBeEnabled();

        const toggle = page.getByRole('checkbox', { name: /Mail bei jeder Änderung an Geräten/ });
        await toggle.click();
        await expect(toggle).not.toBeChecked();
        await expect(page.getByRole('alert')).toHaveCount(2);
    });
});
