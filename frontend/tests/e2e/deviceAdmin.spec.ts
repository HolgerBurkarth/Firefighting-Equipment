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
 * Gerätedetail für Admins (`geraete/<id>/details`, KONZEPT.md, "Bereiche des Admins" und
 * Entscheidung 33): Stand, Stammdaten, Verlauf; Bearbeiten, Prüfdatum, Code zuordnen, Aussondern,
 * Wieder aufnehmen. Der simulierte Server ändert das Gerät wie die API (Version + 1, Einträge im
 * Verlauf) und lehnt veraltete Versionen mit 409 version_conflict ab.
 */

const GS = String.fromCharCode(29);
const GS1_CODE = `010400638133393110ABC${GS}21SN7`;

const PATHS: Readonly<Record<number, { id: number; name: string; path: string }>> = {
    11: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
    12: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
    20: { id: 20, name: 'LF 10', path: 'LF 10' },
};
const TYPES: Readonly<Record<number, string>> = {
    1: 'Atemschutzmaske',
    2: 'Atemschutzgerät',
    3: 'Funkgerät',
};
const STATUS_LABELS: Readonly<Record<string, string>> = {
    ready: 'einsatzbereit',
    maintenance: 'in Wartung',
    defective: 'defekt',
    retired: 'ausgesondert',
};
/** Body-Felder → Spalten von DeviceJson. */
const FIELDS = [
    'identifier',
    'scan_code',
    'serial_number',
    'manufacturer',
    'model',
    'procured_on',
    'note',
] as const;

type Respond = (request: MockRequest) => MockResponse | null;

/**
 * Ein Gerät (ID 1) wie die API: GET mit allen Feldern, Verlauf, POST fields, inspection-due,
 * retire, reinstate. `respond` ersetzt die Antwort einer ändernden Anfrage (z. B. 409).
 */
function deviceServer(initial: Record<string, unknown>, respond: Respond = () => null) {
    let device = fullDevice(deviceJson(1, initial));
    let events: Record<string, unknown>[] = [
        eventJson(1, {
            type: 'created',
            type_label: 'Angelegt',
            location: { from: null, to: PATHS[11] },
        }),
    ];
    const write = (
        request: MockRequest,
        change: (sent: Record<string, unknown>) => Record<string, unknown>,
        typeLabel: string,
    ): MockResponse => {
        const replaced = respond(request);
        if (replaced !== null) {
            return replaced;
        }
        const sent = request.body as Record<string, unknown>;
        if (sent['version'] !== device['version']) {
            return {
                status: 409,
                body: {
                    error: 'version_conflict',
                    message: 'Das Gerät wurde inzwischen geändert.',
                    details: { device },
                },
            };
        }
        device = { ...device, ...change(sent), version: Number(device['version']) + 1 };
        events = [
            eventJson(events.length + 1, {
                type: 'field_changed',
                type_label: typeLabel,
                location: null,
                comment: sent['comment'] ?? null,
            }),
            ...events,
        ];
        return { status: 200, body: { device } };
    };
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/devices/1': () => ({ status: 200, body: { device } }),
        'GET api/v1/devices/1/events': (request) => eventsEndpoint(events)(request),
        'GET api/v1/devices': (request) => devicesEndpoint([device])(request),
        'POST api/v1/devices/1/fields': (request) =>
            write(
                request,
                (sent) => {
                    const change: Record<string, unknown> = {};
                    for (const field of FIELDS) {
                        if (field in sent) {
                            change[field] = sent[field];
                        }
                    }
                    if (typeof sent['device_type_id'] === 'number') {
                        const id = sent['device_type_id'];
                        change['device_type'] = { id, name: TYPES[id] };
                    }
                    return change;
                },
                'Stammdaten geändert',
            ),
        'POST api/v1/devices/1/inspection-due': (request) =>
            write(
                request,
                (sent) => ({ next_inspection_due: sent['next_inspection_due'] }),
                'Prüfdatum geändert',
            ),
        'POST api/v1/devices/1/retire': (request) =>
            write(
                request,
                () => ({ status: 'retired', status_label: 'ausgesondert' }),
                'Ausgesondert',
            ),
        'POST api/v1/devices/1/reinstate': (request) =>
            write(
                request,
                (sent) => {
                    const status = String(sent['status']);
                    const locationId = sent['location_id'];
                    return {
                        status,
                        status_label: STATUS_LABELS[status],
                        ...(typeof locationId === 'number' && { location: PATHS[locationId] }),
                        ...('scan_code' in sent && { scan_code: sent['scan_code'] }),
                        ...('identifier' in sent && { identifier: sent['identifier'] }),
                    };
                },
                'Wieder aufgenommen',
            ),
        'POST api/v1/devices/1/state': (request) => {
            const sent = request.body as Record<string, unknown>;
            const status = String(sent['status']);
            device = {
                ...device,
                status,
                status_label: STATUS_LABELS[status],
                version: Number(device['version']) + 1,
            };
            return {
                status: 200,
                body: {
                    device,
                    result: {
                        outcome: 'applied',
                        outcome_label: 'übernommen',
                        reason: null,
                        reason_label: null,
                        message: 'Übernommen.',
                        stale_confirmed: false,
                        client_ref: sent['client_ref'],
                        replayed: false,
                        notice_id: null,
                    },
                },
            };
        },
    };
    return { api, device: () => device };
}

const MASTER_DATA = {
    ...deviceTypeServer([
        { id: 1, name: 'Atemschutzmaske', count: 3 },
        { id: 2, name: 'Atemschutzgerät', count: 1 },
        { id: 3, name: 'Funkgerät', count: 1, active: false },
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
    ]).api,
};

const M1 = {
    serial_number: 'SN-1',
    manufacturer: 'Dräger',
    model: 'FPS 7000',
    procured_on: '2020-03-01',
    note: 'Größe M',
};

let server = deviceServer(M1);

function useServer(initial: Record<string, unknown>, respond?: Respond): void {
    test.beforeEach(() => {
        server = deviceServer(initial, respond);
    });
}

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...MASTER_DATA,
        ...delegate(() => server.api),
    },
});

/** Zuletzt gesendeter Body an `path` (ohne Bereich). */
function lastBody(requests: readonly MockRequest[], path: string): unknown {
    return requests.filter((request) => request.path === path).at(-1)?.body;
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/geraete/1/details');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        String(server.device()['identifier']),
    );
}

function dialog(page: Page) {
    return page.getByTestId('form-dialog');
}

async function choose(page: Page, label: string, option: string): Promise<void> {
    await dialog(page).getByRole('combobox', { name: label }).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

test.describe('Gerätedetail', () => {
    useServer(M1);

    test('aus der Geräteliste: Stand, Stammdaten, Verlauf', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/geraete');
        await page.getByTestId('device-table').getByRole('link', { name: 'M1' }).click();
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('M1');
        await expect(page.getByTestId('device-state')).toContainText('HLF 20 / G1');
        await expect(page.getByTestId('device-state')).toContainText('einsatzbereit');
        await expect(page.getByTestId('device-state')).toContainText(
            'Nächste Prüfung: nicht festgelegt',
        );
        const master = page.getByTestId('master-data');
        await expect(master).toContainText('SeriennummerSN-1');
        await expect(master).toContainText('Beschaffungsdatum01.03.2020');
        await expect(master).toContainText('NotizGröße M');
        await expect(page.getByTestId('history-entry')).toHaveText([/Angelegt/]);
        // „Geräte“ bleibt im Menü hervorgehoben.
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Geräte', exact: true }),
        ).toHaveClass(/menu-link-active/);

        await page.getByRole('button', { name: 'Zurück' }).click();
        await expect(page).toHaveURL(/\/geraete$/);
    });

    test('Bearbeiten: Prüfung beim Tippen, nur geänderte Felder', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog(page)).toContainText('M1 bearbeiten');
        const identifier = dialog(page).getByLabel('Kennung');
        await identifier.fill('M#1');
        await expect(dialog(page).getByText('Nur Buchstaben, Ziffern')).toBeVisible();
        await expect(identifier).toHaveAttribute('aria-invalid', 'true');
        await expect(dialog(page).getByRole('button', { name: 'Speichern' })).toBeDisabled();

        await identifier.fill('M 1');
        await choose(page, 'Gerätetyp', 'Atemschutzgerät');
        await dialog(page).getByLabel('Seriennummer (optional)').fill('SN-99');
        await dialog(page).getByLabel('Hersteller (optional)').fill('');
        await dialog(page).getByLabel('Beschaffungsdatum (optional)').fill('2021-05-04');
        await dialog(page).getByLabel('Kommentar für den Verlauf (optional)').fill('Typenschild');
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('success-notice')).toHaveText(
            'Stammdaten von M 1 gespeichert.',
        );
        await expect(dialog(page)).toHaveCount(0);
        expect(lastBody(apiRequests, 'api/v1/devices/1/fields')).toEqual({
            version: 1,
            comment: 'Typenschild',
            identifier: 'M 1',
            device_type_id: 2,
            serial_number: 'SN-99',
            manufacturer: null,
            procured_on: '2021-05-04',
        });
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('M 1');
        const master = page.getByTestId('master-data');
        await expect(master).toContainText('GerätetypAtemschutzgerät');
        await expect(master).toContainText('Hersteller–');
        await expect(master).toContainText('Beschaffungsdatum04.05.2021');
        // Der Verlauf lädt neu.
        await expect(page.getByTestId('history-entry')).toHaveCount(2);
        // Fokus zurück auf „Bearbeiten“.
        await expect(page.getByRole('button', { name: 'Bearbeiten' })).toBeFocused();
    });

    test('Fenster mit Eingabe: Klick daneben schließt nicht, Escape fragt nach, „Abbrechen“ schließt', async ({
        page,
        apiRequests,
    }) => {
        // Live-Test 31.10.2026: Ein Klick neben das Fenster (z. B. auf „Jetzt aktualisieren“)
        // schloss es und verwarf die Eingabe ohne Rückfrage.
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        const serial = dialog(page).getByLabel('Seriennummer (optional)');
        await serial.fill('SN-neu');
        await page.mouse.click(5, 5);
        await expect(dialog(page)).toBeVisible();
        await expect(serial).toHaveValue('SN-neu');

        // Escape: Rückfrage, Standard „Weiter bearbeiten“ (Fokus, Enter).
        await page.keyboard.press('Escape');
        const discard = page.getByRole('alertdialog', { name: 'Eingaben verwerfen?' });
        await expect(discard).toContainText('Ihre Eingaben in diesem Fenster gehen verloren.');
        await expect(discard.getByRole('button', { name: 'Weiter bearbeiten' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(discard).toBeHidden();
        await expect(dialog(page)).toBeVisible();
        await expect(serial).toHaveValue('SN-neu');
        // Escape in der Rückfrage schließt nur die Rückfrage.
        await page.keyboard.press('Escape');
        await expect(discard).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(discard).toBeHidden();
        await expect(serial).toHaveValue('SN-neu');

        // Bewusst verworfen: Fenster zu, nichts gesendet; neu geöffnet mit dem gespeicherten Stand.
        await page.keyboard.press('Escape');
        await discard.getByRole('button', { name: 'Verwerfen' }).click();
        await expect(dialog(page)).toHaveCount(0);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(serial).toHaveValue('SN-1');

        // „Abbrechen“ schließt ohne Rückfrage.
        await serial.fill('SN-anders');
        await dialog(page).getByRole('button', { name: 'Abbrechen' }).click();
        await expect(dialog(page)).toHaveCount(0);
        await expect(discard).toHaveCount(0);
        expect(apiRequests.filter((r) => r.method === 'POST')).toHaveLength(0);
    });

    test('Fenster ohne Eingabe: Klick daneben und Escape schließen sofort', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await expect(dialog(page)).toBeVisible();
        await page.mouse.click(5, 5);
        await expect(dialog(page)).toHaveCount(0);
        await page.getByRole('button', { name: 'Prüfdatum ändern' }).click();
        await expect(dialog(page)).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(dialog(page)).toHaveCount(0);
        await expect(page.getByRole('alertdialog')).toHaveCount(0);
    });

    test('Bearbeiten ohne Änderung schließt ohne Anfrage', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog(page)).toHaveCount(0);
        expect(apiRequests.filter((r) => r.method === 'POST')).toHaveLength(0);
    });

    test('Prüfdatum setzen und entfernen', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Prüfdatum ändern' }).click();
        await expect(dialog(page)).toContainText('Bisher ist kein Prüfdatum festgelegt.');
        await dialog(page).getByLabel('Nächste Prüfung fällig am').fill('2027-12-01');
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Nächste Prüfung von M1: 01.12.2027.',
        );
        await expect(page.getByTestId('device-state')).toContainText('Nächste Prüfung: 01.12.2027');
        expect(lastBody(apiRequests, 'api/v1/devices/1/inspection-due')).toEqual({
            version: 1,
            next_inspection_due: '2027-12-01',
        });

        await page.getByRole('button', { name: 'Prüfdatum ändern' }).click();
        await expect(dialog(page)).toContainText('Bisher: 01.12.2027');
        await dialog(page).getByLabel('Nächste Prüfung fällig am').fill('');
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('Prüfdatum von M1 entfernt.');
        expect(lastBody(apiRequests, 'api/v1/devices/1/inspection-due')).toEqual({
            version: 2,
            next_inspection_due: null,
        });
    });

    test('Code zuordnen per Handscanner (Enter) und wieder entfernen', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Code zuordnen' }).click();
        // Das Feld hat den Fokus: Der Handscanner tippt den Code und Enter.
        await expect(dialog(page).getByLabel('Scan-Code')).toBeFocused();
        await page.keyboard.type('4006381333931');
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Code 4006381333931 ist jetzt M1 zugeordnet.',
        );
        expect(lastBody(apiRequests, 'api/v1/devices/1/fields')).toEqual({
            version: 1,
            scan_code: '4006381333931',
        });
        await expect(page.getByTestId('master-data')).toContainText('Scan-Code4006381333931');

        await page.getByRole('button', { name: 'Code zuordnen' }).click();
        await expect(dialog(page)).toContainText('Bisher: 4006381333931');
        await dialog(page).getByRole('button', { name: 'Code entfernen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('Scan-Code von M1 entfernt.');
        expect(lastBody(apiRequests, 'api/v1/devices/1/fields')).toEqual({
            version: 2,
            scan_code: null,
        });
    });

    test('Aussondern mit Rückfrage', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Aussondern …' }).click();
        await expect(dialog(page)).toContainText('M1 (Atemschutzmaske) aussondern?');
        await dialog(page).getByLabel('Grund (optional)').fill('Sichtprüfung nicht bestanden');
        await dialog(page).getByRole('button', { name: 'M1 aussondern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'M1 ist ausgesondert. Kennung und Scan-Code sind wieder frei.',
        );
        expect(lastBody(apiRequests, 'api/v1/devices/1/retire')).toEqual({
            version: 1,
            comment: 'Sichtprüfung nicht bestanden',
        });
        await expect(page.getByTestId('device-retired')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Umbuchen / Status ändern' })).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Bearbeiten' })).toHaveCount(0);
        await expect(page.getByTestId('danger-zone')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Wieder aufnehmen' })).toBeVisible();
    });

    test('Umbuchen vom Gerätedetail, danach zurück mit Bestätigung', async ({ page }) => {
        await open(page);
        await page.getByRole('link', { name: 'Umbuchen / Status ändern' }).click();
        await expect(page).toHaveURL(/\/geraete\/1$/);
        await page.getByRole('button', { name: 'in Wartung', exact: true }).click();
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
        await expect(page.getByTestId('booking-notice')).toContainText('M1');
        await expect(page.getByTestId('device-state')).toContainText('in Wartung');

        // Von der Umbuchen-Seite führt ein Link für Admins zum Gerätedetail.
        await page.getByRole('link', { name: 'Umbuchen / Status ändern' }).click();
        await page.getByRole('link', { name: 'Gerätedetail (Admin)' }).click();
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
    });

    test('Handy: Aktionen und Fenster ohne Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        const main = await page
            .getByRole('link', { name: 'Umbuchen / Status ändern' })
            .boundingBox();
        expect(Math.round(main?.height ?? 0)).toBeGreaterThanOrEqual(56);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        const box = await dialog(page).boundingBox();
        expect((box?.x ?? -1) >= 0 && (box?.width ?? 999) <= 390).toBe(true);
    });
});

test.describe('Gerätedetail: Code per Kamera', () => {
    useServer(M1);

    test('gelesener GS1-Code steht im Fenster und wird gespeichert', async ({
        page,
        apiRequests,
    }) => {
        await fakeCamera(page, { kind: 'code', symbol: await barcode(GS1_CODE, 'QRCode') });
        await open(page);
        await page.getByRole('button', { name: 'Code zuordnen' }).click();
        await dialog(page).getByRole('button', { name: 'Mit der Kamera scannen' }).click();
        // Der Scanner liest, schließt sich, und das Fenster öffnet sich mit dem Code.
        await expect(dialog(page).getByTestId('code-preview')).toHaveText(
            'Erkannt: 010400638133393110ABC[GS]21SN7',
        );
        await expect(page).toHaveURL(/\/geraete\/1\/details$/);
        expect(await cameraCounts(page)).toEqual(cameraReleased(1));
        await dialog(page).getByRole('button', { name: 'Code speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'Code 010400638133393110ABC[GS]21SN7 ist jetzt M1 zugeordnet.',
        );
        expect(lastBody(apiRequests, 'api/v1/devices/1/fields')).toEqual({
            version: 1,
            scan_code: GS1_CODE,
        });
    });
});

test.describe('Gerätedetail: Ablehnungen des Servers', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });
    useServer(M1, (request) => {
        const sent = request.body as Record<string, unknown>;
        if ('identifier' in sent) {
            return {
                status: 409,
                body: {
                    error: 'identifier_taken',
                    message: 'Die Kennung „M2“ ist schon vergeben.',
                },
            };
        }
        if (typeof sent['scan_code'] === 'string') {
            return {
                status: 409,
                body: {
                    error: 'scan_code_taken',
                    message: 'Der Scan-Code ist schon einem anderen Gerät zugeordnet.',
                },
            };
        }
        return null;
    });

    test('Kennung vergeben: Meldung am Feld, Fenster bleibt offen', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await dialog(page).getByLabel('Kennung').fill('M2');
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog(page).getByText('Die Kennung „M2“ ist schon vergeben.')).toBeVisible();
        await expect(dialog(page).getByLabel('Kennung')).toHaveAttribute('aria-invalid', 'true');
        // Neue Eingabe: Die Meldung verschwindet.
        await dialog(page).getByLabel('Kennung').fill('M3');
        await expect(dialog(page).getByText('ist schon vergeben')).toHaveCount(0);
    });

    test('Scan-Code vergeben: Meldung am Feld', async ({ page }) => {
        await open(page);
        await page.getByRole('button', { name: 'Code zuordnen' }).click();
        await dialog(page).getByLabel('Scan-Code').fill('ABC');
        await dialog(page).getByRole('button', { name: 'Code speichern' }).click();
        await expect(
            dialog(page).getByText('Der Scan-Code ist schon einem anderen Gerät zugeordnet.'),
        ).toBeVisible();
    });
});

test.describe('Gerätedetail: Konflikt', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });
    useServer(M1);

    test('eigene Änderung bleibt, der Rest folgt dem neuen Stand', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Bearbeiten' }).click();
        await dialog(page).getByLabel('Modell (optional)').fill('FPS 7000 RA');
        // Inzwischen ändert jemand anderes den Hersteller.
        server = deviceServer({ ...M1, manufacturer: 'MSA', version: 2 });
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog(page).getByTestId('edit-conflict')).toBeVisible();
        await expect(dialog(page).getByLabel('Hersteller (optional)')).toHaveValue('MSA');
        await expect(dialog(page).getByLabel('Modell (optional)')).toHaveValue('FPS 7000 RA');
        await expect(page.getByTestId('master-data')).toContainText('HerstellerMSA');

        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toBeVisible();
        expect(lastBody(apiRequests, 'api/v1/devices/1/fields')).toEqual({
            version: 2,
            model: 'FPS 7000 RA',
        });
    });
});

test.describe('Gerätedetail: Wieder aufnehmen', () => {
    // Ausgesondert in G2, das inzwischen gesperrt ist; mit Scan-Code.
    useServer({
        ...M1,
        status: 'retired',
        status_label: 'ausgesondert',
        location: PATHS[12],
        scan_code: 'ABC',
        version: 4,
    });

    test('gesperrter Standort fällt auf; Status, Standort, Scan-Code entfernen', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await expect(page.getByTestId('device-retired')).toBeVisible();
        await page.getByRole('button', { name: 'Wieder aufnehmen' }).click();
        await expect(dialog(page)).toContainText('M1 wieder aufnehmen');
        await expect(dialog(page).getByTestId('location-problem')).toHaveText(
            'Dieser Standort ist gesperrt. Bitte wählen Sie einen anderen.',
        );
        await expect(dialog(page).getByRole('button', { name: 'Wieder aufnehmen' })).toBeDisabled();
        await choose(page, 'Standort', 'LF 10');
        await dialog(page).getByLabel('in Wartung').check();
        await dialog(page).getByLabel('Scan-Code ABC behalten').uncheck();
        await dialog(page).getByRole('button', { name: 'Wieder aufnehmen' }).click();

        await expect(page.getByTestId('success-notice')).toHaveText(
            'M1 ist wieder aufgenommen: LF 10 · in Wartung.',
        );
        expect(lastBody(apiRequests, 'api/v1/devices/1/reinstate')).toEqual({
            version: 4,
            status: 'maintenance',
            location_id: 20,
            scan_code: null,
        });
        await expect(page.getByTestId('device-retired')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Bearbeiten' })).toBeVisible();
        await expect(page.getByTestId('master-data')).toContainText('Scan-Code–');
    });
});

test.describe('Gerätedetail: Wieder aufnehmen, Kennung und Scan-Code vergeben', () => {
    test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });
    // Wie der Server: Kennung bzw. Scan-Code inzwischen vergeben, Meldung mit Feldern der API.
    useServer(
        {
            ...M1,
            status: 'retired',
            status_label: 'ausgesondert',
            location: PATHS[11],
            scan_code: 'ABC',
            version: 4,
        },
        (request) => {
            const sent = request.body as Record<string, unknown>;
            if (!('identifier' in sent)) {
                return {
                    status: 409,
                    body: {
                        error: 'identifier_taken',
                        message:
                            'Die Kennung „M1“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen eine neue Kennung angeben (Feld "identifier").',
                    },
                };
            }
            if (!('scan_code' in sent)) {
                return {
                    status: 409,
                    body: {
                        error: 'scan_code_taken',
                        message:
                            'Der Scan-Code „ABC“ ist inzwischen an ein anderes Gerät vergeben. Bitte beim Wiederaufnehmen einen neuen Scan-Code angeben (Feld "scan_code") oder ihn mit null entfernen.',
                    },
                };
            }
            return null;
        },
    );

    test('eigene Meldungen am Feld ohne Felder der API', async ({ page, apiRequests }) => {
        await open(page);
        await page.getByRole('button', { name: 'Wieder aufnehmen' }).click();
        const form = dialog(page);
        await form.getByRole('button', { name: 'Wieder aufnehmen' }).click();
        await expect(
            form.getByText(
                'Die Kennung „M1“ ist inzwischen an ein anderes Gerät vergeben. Bitte geben Sie eine andere Kennung ein.',
            ),
        ).toBeVisible();
        await expect(form.getByLabel('Kennung')).toHaveAttribute('aria-invalid', 'true');
        await expect(form).not.toContainText('Feld "');

        await form.getByLabel('Kennung').fill('M1a');
        await expect(form.getByText('ist inzwischen an ein anderes Gerät vergeben')).toHaveCount(0);
        await form.getByRole('button', { name: 'Wieder aufnehmen' }).click();
        await expect(form.getByTestId('scan-code-error')).toHaveText(
            'Dieser Scan-Code ist inzwischen einem anderen Gerät zugeordnet. Entfernen Sie das Häkchen, um das Gerät ohne Scan-Code wieder aufzunehmen.',
        );
        await expect(form).not.toContainText('null');

        await form.getByLabel('Scan-Code ABC behalten').uncheck();
        await expect(form.getByTestId('scan-code-error')).toHaveCount(0);
        await form.getByRole('button', { name: 'Wieder aufnehmen' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            'M1a ist wieder aufgenommen: HLF 20 / G1 · einsatzbereit.',
        );
        expect(lastBody(apiRequests, 'api/v1/devices/1/reinstate')).toEqual({
            version: 4,
            status: 'ready',
            identifier: 'M1a',
            scan_code: null,
        });
    });
});
