import { expect, meBody, test } from './fixtures';
import { barcode, cameraCounts, fakeCamera, type BarcodeSymbol } from './support/camera';
import {
    deviceDetail,
    deviceJson,
    devicesEndpoint,
    LOCATIONS,
    manyDevices,
} from './support/devices';

/**
 * Kamera-Scanner auf „Suchen“ (KONZEPT.md, Entscheidungen 17 und 27). Die Kamera ist simuliert
 * (support/camera.ts: Canvas mit einem echten Code), echt sind Lesemodul, WASM-Datei aus assets/,
 * CSP ('wasm-unsafe-eval') und die Auslieferung durch PHP.
 */

const ME = { status: 200, body: meBody('user') } as const;
const GS = String.fromCharCode(29);
const GS1_CODE = `0104006381333931${GS}10ABC`;

const DEVICES: Record<string, unknown>[] = [
    deviceJson(1, { identifier: 'M1' }),
    deviceJson(4, { identifier: 'PA 3' }),
    deviceJson(5, { identifier: 'HRT 5', scan_code: GS1_CODE }),
];

const DEVICE_PAGES = {
    ...Object.fromEntries(DEVICES.flatMap((device) => Object.entries(deviceDetail(device)))),
    ...LOCATIONS,
};

let gs1Qr: BarcodeSymbol;
let identifierBarcode: BarcodeSymbol;
let unknownQr: BarcodeSymbol;

test.beforeAll(async () => {
    gs1Qr = await barcode(GS1_CODE, 'QRCode');
    identifierBarcode = await barcode('pa 3', 'Code128');
    unknownQr = await barcode('UNBEKANNT-9', 'QRCode');
});

test.describe('Code scannen (lokale Liste)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(DEVICES),
            ...DEVICE_PAGES,
        },
    });

    test('GS1-Code (QR mit GS) öffnet das Gerät, Kamera danach aus', async ({ page }) => {
        await fakeCamera(page, { kind: 'code', symbol: gs1Qr });
        const wasm = page.waitForResponse((response) => response.url().endsWith('.wasm'));
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();

        // Lesemodul vom eigenen Server (assets/, mit Hash), komprimiert, als WebAssembly.
        const response = await wasm;
        expect(new URL(response.url()).pathname).toMatch(
            /^\/firefighting\/test\/assets\/zxing_reader-[A-Za-z0-9_-]+\.wasm$/,
        );
        expect(response.headers()['content-type']).toBe('application/wasm');
        expect(response.headers()['cache-control']).toBe('public, max-age=31536000, immutable');

        await expect(page).toHaveURL(/\/geraete\/5$/);
        await expect(page.getByRole('dialog', { name: 'Code scannen' })).toHaveCount(0);
        expect(await cameraCounts(page)).toEqual({ opened: 1, stopped: 1 });

        // Der Scanner ersetzt sich durch das Gerät: Zurück führt zu „Suchen“ ohne Scanner.
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
        await expect(page.getByRole('dialog', { name: 'Code scannen' })).toHaveCount(0);
    });

    test('Kennung als Barcode (Code 128) ohne Groß-/Kleinschreibung', async ({ page }) => {
        await fakeCamera(page, { kind: 'code', symbol: identifierBarcode });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        await expect(page).toHaveURL(/\/geraete\/4$/);
    });

    test('kein Treffer: Hinweis, Text im Suchfeld, Fokus auf dem Knopf', async ({ page }) => {
        await fakeCamera(page, { kind: 'code', symbol: unknownQr });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();

        await expect(page.getByTestId('search-not-found')).toHaveText(
            'Kein Gerät mit diesem Code gefunden.',
        );
        await expect(page).toHaveURL(/\/suchen$/);
        await expect(page.getByRole('searchbox', { name: 'Gerät suchen' })).toHaveValue(
            'UNBEKANNT-9',
        );
        await expect(page.getByRole('button', { name: 'Code scannen' })).toBeFocused();
        expect(await cameraCounts(page)).toEqual({ opened: 1, stopped: 1 });

        // Tippen blendet den Hinweis aus.
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('M1');
        await expect(page.getByTestId('search-not-found')).toHaveCount(0);
    });

    test('Zurück-Knopf des Browsers schließt nur den Scanner, Kamera aus', async ({ page }) => {
        await fakeCamera(page, { kind: 'blank' });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('searchbox', { name: 'Gerät suchen' }).fill('M');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        await expect(
            page.getByText('Halten Sie den Barcode oder QR-Code in den Rahmen.'),
        ).toBeVisible();
        await expect.poll(async () => (await cameraCounts(page)).opened).toBe(1);

        await page.goBack();
        await expect(page).toHaveURL(/\/suchen$/);
        await expect(page.getByRole('dialog', { name: 'Code scannen' })).toHaveCount(0);
        await expect(page.getByRole('searchbox', { name: 'Gerät suchen' })).toHaveValue('M');
        expect(await cameraCounts(page)).toEqual({ opened: 1, stopped: 1 });
    });

    test('Schließen und Escape; direkt geöffnete Adresse wird ersetzt', async ({ page }) => {
        await fakeCamera(page, { kind: 'blank' });
        await page.goto('/firefighting/test/suchen?scannen=1');
        const dialog = page.getByRole('dialog', { name: 'Code scannen' });
        await expect(dialog.getByRole('heading', { name: 'Code scannen' })).toBeFocused();
        await dialog.getByRole('button', { name: 'Scanner schließen' }).click();
        await expect(page).toHaveURL(/\/suchen$/);
        await expect(dialog).toHaveCount(0);

        await page.getByRole('button', { name: 'Code scannen' }).click();
        await expect(dialog).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog).toHaveCount(0);
        await expect(page).toHaveURL(/\/suchen$/);

        await page.getByRole('button', { name: 'Code scannen' }).click();
        await dialog.getByRole('button', { name: 'Abbrechen' }).click();
        await expect(dialog).toHaveCount(0);
        await expect.poll(async () => cameraCounts(page)).toEqual({ opened: 3, stopped: 3 });
    });

    test('Kamera nicht erlaubt: Meldung, erneut versuchen', async ({ page }) => {
        await fakeCamera(page, { kind: 'error', name: 'NotAllowedError' });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        const error = page.getByTestId('scanner-error');
        await expect(error).toContainText('Der Zugriff auf die Kamera ist nicht erlaubt.');
        await error.getByRole('button', { name: 'Erneut versuchen' }).click();
        await expect(error).toContainText('Der Zugriff auf die Kamera ist nicht erlaubt.');
    });

    test('ohne Kamera im Browser: Hinweis ohne erneuten Versuch', async ({ page }) => {
        await fakeCamera(page, { kind: 'unsupported' });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        const error = page.getByTestId('scanner-error');
        await expect(error).toContainText('Dieser Browser kann die Kamera nicht verwenden.');
        await expect(error.getByRole('button')).toHaveCount(0);
    });

    test('Handy-Breite: Knopf unter dem Suchfeld, Scanner bildschirmfüllend', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await fakeCamera(page, { kind: 'blank' });
        await page.goto('/firefighting/test/suchen');
        const button = page.getByRole('button', { name: 'Code scannen' });
        const field = await page.getByRole('searchbox', { name: 'Gerät suchen' }).boundingBox();
        const box = await button.boundingBox();
        expect(box?.height).toBeGreaterThanOrEqual(48);
        expect((box?.y ?? 0) > (field?.y ?? 0)).toBe(true);

        await button.click();
        await expect(page.getByTestId('scanner-video')).toBeVisible();
        // Deckt das ganze Fenster ab; Maße aus der Seite (WebKit unter Windows rechnet mit etwas
        // kleineren als den eingestellten).
        const covers = await page
            .getByRole('dialog', { name: 'Code scannen' })
            .evaluate((dialog) => {
                const rect = dialog.getBoundingClientRect();
                return (
                    getComputedStyle(dialog).position === 'fixed' &&
                    rect.top <= 0 &&
                    rect.width >= document.documentElement.clientWidth - 1 &&
                    rect.bottom >= window.innerHeight - 1
                );
            });
        expect(covers).toBe(true);
    });
});

test.describe('Code scannen (mehr als 2000 Geräte, Suche auf dem Server)', () => {
    const hrt = deviceJson(4711, { identifier: 'HRT 5', scan_code: GS1_CODE });
    test.use({
        api: {
            'GET api/v1/auth/me': ME,
            'GET api/v1/devices': devicesEndpoint(manyDevices(200), () => [hrt], 2001),
            ...deviceDetail(hrt),
            ...LOCATIONS,
        },
    });

    test('fragt scan_code genau und öffnet das Gerät', async ({ page, apiRequests }) => {
        await fakeCamera(page, { kind: 'code', symbol: gs1Qr });
        await page.goto('/firefighting/test/suchen');
        await page.getByRole('button', { name: 'Code scannen' }).click();
        await expect(page).toHaveURL(/\/geraete\/4711$/);
        const scanCodes = apiRequests
            .map((request) => new URL(request.url).searchParams.get('scan_code'))
            .filter((code) => code !== null);
        expect(scanCodes).toEqual([GS1_CODE]);
    });
});
