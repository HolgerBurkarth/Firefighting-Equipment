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
import { barcode, cameraCounts, cameraReleased, fakeCamera } from './support/camera';
import {
    deviceJson,
    devicesEndpoint,
    eventJson,
    eventsEndpoint,
    fullDevice,
} from './support/devices';
import { horizontalOverflow } from './support/layout';
import { delegate, deviceTypeServer, locationServer } from './support/masterData';

/**
 * Neues Gerät (`geraete/neu`, KONZEPT.md, "Admin-Konsole – Entscheidungen", 32): Prüfung beim
 * Tippen, „Speichern und nächstes“ (Typ, Standort, Prüfdatum bleiben, Kennung zählt weiter),
 * „Speichern“ öffnet das Gerätedetail, Scan-Code per Handscanner (Enter speichert nicht) oder
 * Kamera, Ablehnungen des Servers am Feld. Der simulierte Server legt Geräte ab ID 100 an.
 */

const GS = String.fromCharCode(29);
const GS1_CODE = `010400638133393110ABC${GS}21SN7`;

const PATHS: Readonly<Record<number, { id: number; name: string; path: string }>> = {
    11: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
    20: { id: 20, name: 'LF 10', path: 'LF 10' },
};
const TYPES: Readonly<Record<number, string>> = { 1: 'Atemschutzmaske', 2: 'Atemschutzgerät' };

type Respond = (request: MockRequest) => MockResponse | null;

/** POST devices wie die API (201 mit allen Feldern), dazu GET des ersten angelegten Geräts. */
function deviceServer(respond: Respond = () => null) {
    const created: Record<string, unknown>[] = [];
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/devices': (request) => devicesEndpoint(created)(request),
        'POST api/v1/devices': (request) => {
            const replaced = respond(request);
            if (replaced !== null) {
                return replaced;
            }
            const sent = request.body as Record<string, unknown>;
            const typeId = Number(sent['device_type_id']);
            const device = fullDevice(
                deviceJson(100 + created.length, {
                    identifier: sent['identifier'],
                    scan_code: sent['scan_code'] ?? null,
                    device_type: { id: typeId, name: TYPES[typeId] },
                    location: PATHS[Number(sent['location_id'])],
                    next_inspection_due: sent['next_inspection_due'] ?? null,
                }),
            );
            created.push(device);
            return { status: 201, body: { device } };
        },
        'GET api/v1/devices/100': () =>
            created[0] === undefined
                ? { status: 404, body: { error: 'not_found', message: 'Nicht gefunden.' } }
                : { status: 200, body: { device: created[0] } },
        'GET api/v1/devices/100/events': (request) =>
            eventsEndpoint([
                eventJson(1, { type: 'created', type_label: 'Angelegt', comment: null }),
            ])(request),
    };
    return { api };
}

let server = deviceServer();

function useServer(respond?: Respond): void {
    test.beforeEach(() => {
        server = deviceServer(respond);
    });
}

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...deviceTypeServer([
            { id: 1, name: 'Atemschutzmaske', count: 3 },
            { id: 2, name: 'Atemschutzgerät', count: 1 },
            { id: 3, name: 'Funkgerät', active: false },
        ]),
        ...locationServer([
            {
                id: 10,
                name: 'HLF 20',
                children: [
                    { id: 11, name: 'G1', count: 1 },
                    { id: 12, name: 'G2', active: false },
                ],
            },
            { id: 20, name: 'LF 10' },
            { id: 30, name: 'Altes Lager', kind: 'room', active: false },
        ]).api,
        ...delegate(() => server.api),
    },
});

function posts(requests: readonly MockRequest[]): unknown[] {
    return requests
        .filter((request) => request.method === 'POST' && request.path === 'api/v1/devices')
        .map((request) => request.body);
}

async function choose(page: Page, label: string, option: string): Promise<void> {
    await page.getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/geraete/neu');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Neues Gerät');
}

test.describe('Neues Gerät', () => {
    useServer();

    test('aus der Geräteliste, Fokus auf der Kennung', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/geraete');
        await page.getByRole('link', { name: 'Neues Gerät' }).click();
        await expect(page).toHaveURL(/\/geraete\/neu$/);
        await expect(page.getByLabel('Kennung')).toBeFocused();
        // Menü: „Geräte“ bleibt hervorgehoben.
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Geräte', exact: true }),
        ).toHaveClass(/menu-link-active/);
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
    });

    test('nur aktive Gerätetypen und Standorte zur Auswahl', async ({ page }) => {
        await open(page);
        await page.getByRole('combobox', { name: 'Gerätetyp' }).click();
        await expect(page.getByRole('option')).toHaveText(['Atemschutzgerät', 'Atemschutzmaske']);
        await page.keyboard.press('Escape');
        await page.getByRole('combobox', { name: 'Standort' }).click();
        await expect(page.getByRole('option')).toHaveText(['HLF 20', 'HLF 20 / G1', 'LF 10']);
    });

    test('ohne Eingaben: Meldungen an den Pflichtfeldern, keine Anfrage', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page.getByText('Bitte eine Kennung eingeben.')).toBeVisible();
        await expect(page.getByTestId('type-problem')).toHaveText('Bitte einen Gerätetyp wählen.');
        await expect(page.getByTestId('location-problem')).toHaveText(
            'Bitte einen Standort wählen.',
        );
        await expect(page.getByLabel('Kennung')).toBeFocused();
        await page.getByLabel('Kennung').fill('M 24!');
        await expect(page.getByText('Nur Buchstaben, Ziffern')).toBeVisible();
        expect(posts(apiRequests)).toEqual([]);
    });

    test('Speichern und nächstes: Typ, Standort und Prüfdatum bleiben, Kennung zählt weiter', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByLabel('Kennung').fill('M24');
        await choose(page, 'Gerätetyp', 'Atemschutzmaske');
        await choose(page, 'Standort', 'HLF 20 / G1');
        await page.getByRole('radio', { name: 'in Wartung' }).check();
        await page.getByLabel('Nächste Prüfung (optional)').fill('2027-03-01');
        await page.getByLabel('Seriennummer (optional)').fill('SN-24');
        await page.getByLabel('Kommentar für den Verlauf (optional)').fill('Neu beschafft');
        // Enter in einem Feld = „Speichern und nächstes“.
        await page.getByLabel('Seriennummer (optional)').press('Enter');

        await expect(page.getByTestId('created')).toHaveText('✓ M24 angelegt. Gerät öffnen');
        expect(posts(apiRequests)).toEqual([
            {
                identifier: 'M24',
                device_type_id: 1,
                serial_number: 'SN-24',
                location_id: 11,
                status: 'maintenance',
                next_inspection_due: '2027-03-01',
                comment: 'Neu beschafft',
            },
        ]);
        await expect(page.getByLabel('Kennung')).toHaveValue('M25');
        await expect(page.getByLabel('Kennung')).toBeFocused();
        await expect(page.getByRole('combobox', { name: 'Gerätetyp' })).toHaveText(
            /Atemschutzmaske/,
        );
        await expect(page.getByRole('combobox', { name: 'Standort' })).toHaveText(/HLF 20 \/ G1/);
        await expect(page.getByLabel('Nächste Prüfung (optional)')).toHaveValue('2027-03-01');
        await expect(page.getByRole('radio', { name: 'einsatzbereit' })).toBeChecked();
        await expect(page.getByLabel('Seriennummer (optional)')).toHaveValue('');
        await expect(page.getByLabel('Kommentar für den Verlauf (optional)')).toHaveValue('');

        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByTestId('created')).toContainText('M25 angelegt.');
        expect(posts(apiRequests)[1]).toEqual({
            identifier: 'M25',
            device_type_id: 1,
            location_id: 11,
            status: 'ready',
            next_inspection_due: '2027-03-01',
        });
        await expect(page.getByLabel('Kennung')).toHaveValue('M26');

        await expect(page.getByRole('link', { name: 'Gerät öffnen' })).toHaveAttribute(
            'href',
            /\/geraete\/101\/details$/,
        );
    });

    test('Speichern öffnet das neue Gerät mit Bestätigung', async ({ page }) => {
        await open(page);
        await page.getByLabel('Kennung').fill('PA 7');
        await choose(page, 'Gerätetyp', 'Atemschutzgerät');
        await choose(page, 'Standort', 'LF 10');
        await page.getByRole('button', { name: 'Speichern', exact: true }).click();
        await expect(page).toHaveURL(/\/geraete\/100\/details$/);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('PA 7');
        await expect(page.getByTestId('booking-notice')).toHaveText('✓ PA 7 angelegt.');
        // Das Formular ist ersetzt: Zurück führt nicht wieder dorthin.
        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
    });

    test('Handscanner: Enter im Scan-Code speichert nicht, sondern führt weiter', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByLabel('Kennung').fill('M24');
        await choose(page, 'Gerätetyp', 'Atemschutzmaske');
        await choose(page, 'Standort', 'LF 10');
        const scanCode = page.getByLabel('Scan-Code (optional)');
        // Ohne GS: fill() lässt das Steuerzeichen in Firefox weg (GS prüft der Kamera-Test).
        await scanCode.fill('4006381333931');
        await scanCode.press('Enter');
        await expect(page.getByLabel('Nächste Prüfung (optional)')).toBeFocused();
        expect(posts(apiRequests)).toEqual([]);
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByTestId('created')).toContainText('M24 angelegt.');
        expect(posts(apiRequests)[0]).toMatchObject({ scan_code: '4006381333931' });
        await expect(scanCode).toHaveValue('');
    });
});

test.describe('Neues Gerät: Code per Kamera', () => {
    useServer();

    test('gelesener GS1-Code steht im Feld, die Eingaben bleiben', async ({
        page,
        apiRequests,
    }) => {
        await fakeCamera(page, { kind: 'code', symbol: await barcode(GS1_CODE, 'QRCode') });
        await open(page);
        await page.getByLabel('Kennung').fill('M24');
        await choose(page, 'Gerätetyp', 'Atemschutzmaske');
        await choose(page, 'Standort', 'LF 10');
        await page.getByRole('button', { name: 'Mit der Kamera scannen' }).click();
        await expect(page.getByTestId('code-preview')).toHaveText(
            'Erkannt: 010400638133393110ABC[GS]21SN7',
        );
        await expect(page).toHaveURL(/\/geraete\/neu$/);
        await expect(page.getByLabel('Scan-Code (optional)')).toBeFocused();
        await expect(page.getByLabel('Kennung')).toHaveValue('M24');
        expect(await cameraCounts(page)).toEqual(cameraReleased(1));
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByTestId('created')).toContainText('M24 angelegt.');
        expect(posts(apiRequests)[0]).toMatchObject({ scan_code: GS1_CODE });
    });
});

test.describe('Neues Gerät: Ablehnungen des Servers', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });
    useServer((request) => {
        const sent = request.body as Record<string, unknown>;
        if (sent['identifier'] === 'M1') {
            return {
                status: 409,
                body: {
                    error: 'identifier_taken',
                    message: 'Die Kennung „M1“ ist schon vergeben.',
                },
            };
        }
        if (sent['scan_code'] === 'ABC') {
            return {
                status: 409,
                body: {
                    error: 'scan_code_taken',
                    message: 'Der Scan-Code ist schon einem anderen Gerät zugeordnet.',
                },
            };
        }
        if (sent['location_id'] === 20) {
            return {
                status: 409,
                body: { error: 'rule_violation', message: 'Der Standort ist gesperrt.' },
            };
        }
        return null;
    });

    async function fill(page: Page, identifier: string, location: string): Promise<void> {
        await page.getByLabel('Kennung').fill(identifier);
        await choose(page, 'Gerätetyp', 'Atemschutzmaske');
        await choose(page, 'Standort', location);
    }

    test('Kennung vergeben: Meldung am Feld, Eingaben bleiben', async ({ page }) => {
        await open(page);
        await fill(page, 'M1', 'HLF 20 / G1');
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByText('Die Kennung „M1“ ist schon vergeben.')).toBeVisible();
        await expect(page.getByLabel('Kennung')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByLabel('Kennung')).toBeFocused();
        await page.getByLabel('Kennung').fill('M2');
        await expect(page.getByText('ist schon vergeben')).toHaveCount(0);
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByTestId('created')).toContainText('M2 angelegt.');
    });

    test('Scan-Code vergeben: Meldung am Feld', async ({ page }) => {
        await open(page);
        await fill(page, 'M5', 'HLF 20 / G1');
        await page.getByLabel('Scan-Code (optional)').fill('ABC');
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(
            page.getByText('Der Scan-Code ist schon einem anderen Gerät zugeordnet.'),
        ).toBeVisible();
        await expect(page.getByLabel('Scan-Code (optional)')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
    });

    test('Regelverstoß: Meldung unter dem Formular', async ({ page }) => {
        await open(page);
        await fill(page, 'M5', 'LF 10');
        await page.getByRole('button', { name: 'Speichern und nächstes' }).click();
        await expect(page.getByText('Der Standort ist gesperrt.')).toBeVisible();
        await expect(page.getByLabel('Kennung')).toHaveValue('M5');
    });
});

test.describe('Neues Gerät am Handy', () => {
    useServer();

    test('eine Spalte, kein waagerechter Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        expect(await horizontalOverflow(page)).toBe(0);
        const identifier = await page.getByLabel('Kennung').boundingBox();
        const type = await page.getByRole('combobox', { name: 'Gerätetyp' }).boundingBox();
        // Gerätetyp steht unter der Kennung, nicht daneben.
        expect((type?.y ?? 0) > (identifier?.y ?? 0) + (identifier?.height ?? 0)).toBe(true);
        const save = await page
            .getByRole('button', { name: 'Speichern und nächstes' })
            .boundingBox();
        // Knöpfe über die ganze Breite wie die Felder.
        expect(Math.abs((save?.width ?? 0) - (identifier?.width ?? 0))).toBeLessThan(2);
    });
});
