import type { Page } from '@playwright/test';
import {
    expect,
    meBody,
    NETWORK_ERROR_CONSOLE,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { deviceJson, devicesEndpoint, fullDevice } from './support/devices';
import { horizontalOverflow } from './support/layout';
import { delegate, deviceTypeServer, locationServer } from './support/masterData';

/**
 * Sammelaktionen der Geräteliste (KONZEPT.md Entscheidung 31): Mehrfachauswahl (auch alle Geräte
 * der gefilterten Liste), „Prüfdatum setzen“ und „Umbuchen / Status ändern“ in Blöcken zu 50,
 * Ergebnis je Gerät. Der simulierte Server bucht jedes Gerät wie DeviceBulkHandler einzeln (Version
 * + 1, veraltete Version → Fehler nur für dieses Gerät).
 */

const PATHS: Readonly<Record<number, { id: number; name: string; path: string }>> = {
    10: { id: 10, name: 'HLF 20', path: 'HLF 20' },
    11: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
    30: { id: 30, name: 'Lager', path: 'Lager' },
};
const STATUS_LABELS: Readonly<Record<string, string>> = {
    ready: 'einsatzbereit',
    maintenance: 'in Wartung',
    defective: 'defekt',
};

type Entry = Record<string, unknown> & { id: number; version: number };

function bulkServer(initial: readonly Record<string, unknown>[]) {
    let devices = initial.map((device) => fullDevice(device));
    let failNext = false;
    const handle =
        (apply: (device: Record<string, unknown>, entry: Entry) => Record<string, unknown>) =>
        (request: MockRequest): MockResponse => {
            if (failNext) {
                failNext = false;
                return 'abort';
            }
            const entries = (request.body as { devices: Entry[] }).devices;
            const results = entries.map((entry) => {
                const index = devices.findIndex((device) => device['id'] === entry.id);
                const current = devices[index];
                if (current === undefined) {
                    return {
                        id: entry.id,
                        outcome: 'error',
                        replayed: false,
                        device: null,
                        error: { error: 'not_found', message: 'Die Adresse wurde nicht gefunden.' },
                    };
                }
                if (current['version'] !== entry.version) {
                    return {
                        id: entry.id,
                        outcome: 'error',
                        replayed: false,
                        device: current,
                        error: {
                            error: 'version_conflict',
                            message: 'Das Gerät wurde inzwischen geändert.',
                            details: { device: current },
                        },
                    };
                }
                const changed = apply(current, entry);
                if (JSON.stringify(changed) === JSON.stringify(current)) {
                    return {
                        id: entry.id,
                        outcome: 'unchanged',
                        replayed: false,
                        device: current,
                        error: null,
                    };
                }
                const next = { ...changed, version: current['version'] + 1 };
                devices = devices.map((device, i) => (i === index ? next : device));
                return {
                    id: entry.id,
                    outcome: 'applied',
                    replayed: false,
                    device: next,
                    error: null,
                };
            });
            const count = (outcome: string): number =>
                results.filter((result) => result.outcome === outcome).length;
            return {
                status: 200,
                body: {
                    results,
                    summary: {
                        total: results.length,
                        applied: count('applied'),
                        unchanged: count('unchanged'),
                        errors: count('error'),
                    },
                },
            };
        };
    const api: Record<string, (request: MockRequest) => MockResponse> = {
        'GET api/v1/devices': (request) => devicesEndpoint(devices)(request),
        'POST api/v1/devices/bulk/inspection-due': handle((device, entry) => ({
            ...device,
            next_inspection_due: entry['next_inspection_due'],
        })),
        'POST api/v1/devices/bulk/state': handle((device, entry) => {
            const locationId = entry['location_id'];
            const status = entry['status'];
            return {
                ...device,
                ...(typeof locationId === 'number' && { location: PATHS[locationId] }),
                ...(typeof status === 'string' && { status, status_label: STATUS_LABELS[status] }),
            };
        }),
    };
    return {
        api,
        devices: () => devices,
        /** Ein anderes Konto ändert das Gerät (Version + 1). */
        bump: (id: number): void => {
            devices = devices.map((device) =>
                device['id'] === id
                    ? { ...device, version: Number(device['version']) + 1 }
                    : device,
            );
        },
        failNextRequest: (): void => {
            failNext = true;
        },
    };
}

const MASTER_DATA = {
    ...deviceTypeServer([{ id: 1, name: 'Atemschutzmaske', count: 3 }]),
    ...locationServer([
        { id: 10, name: 'HLF 20', children: [{ id: 11, name: 'G1', count: 3 }] },
        { id: 20, name: 'Altes Lager', kind: 'room', active: false },
        { id: 30, name: 'Lager', kind: 'room' },
    ]).api,
};

const THREE = [
    deviceJson(1),
    deviceJson(2),
    deviceJson(3, { identifier: 'M3', next_inspection_due: '2027-01-15' }),
];

let server = bulkServer(THREE);

function useServer(initial: readonly Record<string, unknown>[]): void {
    test.beforeEach(() => {
        server = bulkServer(initial);
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

function bulkBodies(
    requests: readonly MockRequest[],
    action: string,
): { devices: Entry[]; comment?: string }[] {
    return requests
        .filter((request) => request.path === `api/v1/devices/bulk/${action}`)
        .map((request) => request.body as { devices: Entry[]; comment?: string });
}

function dialog(page: Page) {
    return page.getByTestId('form-dialog');
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/geraete');
    await expect(page.getByTestId('device-count')).toHaveText(/Geräte$/);
}

test.describe('Sammelaktionen (PC)', () => {
    useServer(THREE);

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Prüfdatum für mehrere Geräte: ein Eintrag je Gerät, Ergebnis, Auswahl leer', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await expect(page.getByTestId('selection-bar')).toBeHidden();
        await page.getByRole('checkbox', { name: 'M1 auswählen' }).check();
        await page.getByRole('checkbox', { name: 'M3 auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('2 Geräte ausgewählt');
        await expect(
            page.getByRole('checkbox', { name: 'Alle angezeigten Geräte auswählen' }),
        ).toHaveJSProperty('indeterminate', true);

        await page.getByRole('button', { name: 'Prüfdatum setzen …' }).click();
        await expect(
            dialog(page).getByRole('heading', { name: 'Prüfdatum für 2 Geräte' }),
        ).toBeVisible();
        await dialog(page).getByLabel('Nächste Prüfung fällig am').fill('2027-03-01');
        await dialog(page)
            .getByLabel('Kommentar für den Verlauf (optional)')
            .fill('Prüfung beauftragt');
        await dialog(page).getByRole('button', { name: 'Prüfdatum setzen' }).click();

        await expect(page.getByTestId('bulk-summary')).toHaveText('Prüfdatum: 2 Geräte geändert.');
        await expect(dialog(page)).toBeHidden();
        await expect(page.getByTestId('selection-bar')).toBeHidden();
        await expect(page.getByRole('button', { name: 'Prüfdatum setzen …' })).toBeHidden();
        expect(bulkBodies(apiRequests, 'inspection-due')).toEqual([
            {
                devices: [
                    { id: 1, version: 1, next_inspection_due: '2027-03-01' },
                    { id: 3, version: 1, next_inspection_due: '2027-03-01' },
                ],
                comment: 'Prüfung beauftragt',
            },
        ]);
        // Die Liste lädt neu und zeigt den neuen Stand.
        await expect(page.getByTestId('device-row-1').locator('.cell-due')).toContainText(
            '01.03.2027',
        );
        await expect(page.getByTestId('device-row-3').locator('.cell-due')).toContainText(
            '01.03.2027',
        );
    });

    test('Umbuchen / Status: Pflichtkommentar bei „defekt“, Fehler bleiben ausgewählt', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('checkbox', { name: 'Alle angezeigten Geräte auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('3 Geräte ausgewählt');
        // Inzwischen ändert jemand anderes M2.
        server.bump(2);

        await page.getByRole('button', { name: 'Umbuchen / Status ändern …' }).click();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog(page).getByTestId('bulk-target-problem')).toHaveText(
            'Bitte einen neuen Standort und/oder Status wählen.',
        );
        await dialog(page).getByRole('combobox', { name: 'Neuer Standort' }).click();
        await expect(page.getByRole('option', { name: 'Altes Lager' })).toHaveCount(0);
        await page.getByRole('option', { name: 'Lager', exact: true }).click();
        await dialog(page).getByRole('radio', { name: 'defekt' }).check();
        await expect(
            dialog(page).getByText('Bitte beschreiben Sie den Defekt mit mindestens 5 Zeichen.'),
        ).toBeVisible();
        await dialog(page).getByLabel('Was ist defekt?').fill('Nach Einsatz verschmutzt');
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Umbuchen / Status: 2 Geräte geändert, 1 nicht möglich.',
        );
        await expect(page.getByTestId('bulk-failures')).toContainText(
            'M2: Inzwischen geändert – bitte prüfen und erneut senden.',
        );
        await expect(page.getByTestId('selection-bar')).toContainText('1 Gerät ausgewählt');
        const [first] = bulkBodies(apiRequests, 'state');
        expect(first?.comment).toBe('Nach Einsatz verschmutzt');
        expect(
            first?.devices.map(({ id, version, location_id, status }) => ({
                id,
                version,
                location_id,
                status,
            })),
        ).toEqual([
            { id: 1, version: 1, location_id: 30, status: 'defective' },
            { id: 2, version: 1, location_id: 30, status: 'defective' },
            { id: 3, version: 1, location_id: 30, status: 'defective' },
        ]);
        const refs = first?.devices.map((entry) => entry['client_ref']) ?? [];
        expect(new Set(refs).size).toBe(3);
        await expect(page.getByTestId('device-row-1')).toContainText('Lager');
        await expect(page.getByTestId('device-row-1')).toContainText('defekt');

        // Erneut senden: mit der aktuellen Version.
        await page.getByRole('button', { name: 'Umbuchen / Status ändern …' }).click();
        await expect(
            dialog(page).getByRole('heading', { name: 'Umbuchen / Status ändern (1 Gerät)' }),
        ).toBeVisible();
        await dialog(page).getByRole('radio', { name: 'in Wartung' }).check();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Umbuchen / Status: 1 Gerät geändert.',
        );
        expect(bulkBodies(apiRequests, 'state')[1]?.devices).toEqual([
            { id: 2, version: 2, status: 'maintenance', client_ref: expect.any(String) as unknown },
        ]);
        await expect(page.getByTestId('selection-bar')).toBeHidden();
    });

    test('Filter ändern hebt die Auswahl auf; ausgesonderte sind nicht wählbar', async ({
        page,
    }) => {
        server = bulkServer([
            ...THREE,
            deviceJson(4, { identifier: 'M9', status: 'retired', status_label: 'ausgesondert' }),
        ]);
        await open(page);
        await page.getByRole('checkbox', { name: 'M1 auswählen' }).check();
        await page.getByRole('button', { name: 'defekt', exact: true }).click();
        await expect(page.getByTestId('selection-bar')).toBeHidden();
        await page.getByRole('button', { name: 'defekt', exact: true }).click();
        await page.getByRole('checkbox', { name: 'M9 (ausgesondert, nicht wählbar)' }).waitFor();
        await expect(
            page.getByRole('checkbox', { name: 'M9 (ausgesondert, nicht wählbar)' }),
        ).toBeDisabled();
        await page.getByRole('checkbox', { name: 'Alle angezeigten Geräte auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('3 Geräte ausgewählt');
        await page.getByRole('button', { name: 'Auswahl aufheben' }).click();
        await expect(page.getByTestId('selection-bar')).toBeHidden();
    });
});

test.describe('Sammelaktionen ohne Verbindung', () => {
    useServer(THREE);
    test.use({ allowedConsoleError: NETWORK_ERROR_CONSOLE });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('ohne Verbindung: nichts verloren, die Geräte bleiben ausgewählt', async ({ page }) => {
        await open(page);
        await page.getByRole('checkbox', { name: 'M1 auswählen' }).check();
        await page.getByRole('checkbox', { name: 'M2 auswählen' }).check();
        server.failNextRequest();
        await page.getByRole('button', { name: 'Prüfdatum setzen …' }).click();
        await dialog(page).getByLabel('Nächste Prüfung fällig am').fill('2027-03-01');
        await dialog(page).getByRole('button', { name: 'Prüfdatum setzen' }).click();

        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Prüfdatum: 0 Geräte geändert, 2 nicht gesendet.',
        );
        await expect(page.getByTestId('bulk-result')).toContainText(
            'Nicht gesendete Geräte bleiben ausgewählt',
        );
        await expect(page.getByTestId('selection-bar')).toContainText('2 Geräte ausgewählt');
    });
});

test.describe('Alle Geräte der Liste', () => {
    useServer(Array.from({ length: 120 }, (_, i) => deviceJson(i + 1)));

    test('alle 120 wählen und in Blöcken zu 50 senden', async ({ page, apiRequests }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await open(page);
        await page.getByRole('checkbox', { name: 'Alle angezeigten Geräte auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('50 Geräte ausgewählt');
        await page.getByRole('button', { name: 'Alle 120 Geräte der Liste auswählen' }).click();
        await expect(page.getByTestId('selection-bar')).toContainText('120 Geräte ausgewählt');
        await expect(page.getByRole('button', { name: /Alle 120 Geräte/ })).toBeHidden();

        await page.getByRole('button', { name: 'Prüfdatum setzen …' }).click();
        await expect(
            dialog(page).getByRole('heading', { name: 'Prüfdatum für 120 Geräte' }),
        ).toBeVisible();
        await dialog(page).getByLabel('Nächste Prüfung fällig am').fill('2027-03-01');
        await dialog(page).getByRole('button', { name: 'Prüfdatum setzen' }).click();

        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Prüfdatum: 120 Geräte geändert.',
        );
        expect(
            bulkBodies(apiRequests, 'inspection-due').map((body) => body.devices.length),
        ).toEqual([50, 50, 20]);
        expect(
            server.devices().every((device) => device['next_inspection_due'] === '2027-03-01'),
        ).toBe(true);
    });
});

test.describe('Sammelaktionen (Handy)', () => {
    useServer(THREE);

    test('Karten mit Auswahl, Leiste und Fenster ohne Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await open(page);
        await page.getByRole('checkbox', { name: 'M2 auswählen' }).check();
        await expect(page.getByTestId('selection-bar')).toContainText('1 Gerät ausgewählt');
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
        await page.getByRole('button', { name: 'Umbuchen / Status ändern …' }).click();
        await dialog(page).getByRole('radio', { name: 'in Wartung' }).check();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('bulk-summary')).toHaveText(
            'Umbuchen / Status: 1 Gerät geändert.',
        );
        await expect(page.getByTestId('device-row-2')).toContainText('in Wartung');
        expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    });
});
