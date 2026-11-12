import type { Page } from '@playwright/test';
import { horizontalOverflow, smallTargets, unreachableControls } from './support/layout';
import {
    ADMIN_RIGHTS,
    expect,
    meBody,
    permissionsJson,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { deviceJson, fullDevice } from './support/devices';
import { delegate } from './support/masterData';

/**
 * Seite „Wartung“ (Phase 2, P2.4): Sammelstelle und Werkstatt mit der Ausrüstung dort, Sammelaktionen
 * „Zur Werkstatt“ und „Zurück ins Lager“ (der Server gibt das Ziel vor), Transportliste. Der Bereich
 * kommt nur in Städten mit Sammelstelle oder Werkstatt in den Rechten (Server, Permissions::level).
 */

const AREAS = {
    ...(ADMIN_RIGHTS['areas'] as Record<string, 'read' | 'write'>),
    maintenance: 'write',
} as const;
const ACTIONS = ADMIN_RIGHTS['actions'] as string[];

function admin(level: 'read' | 'write' | null = 'write') {
    const areas = level === null ? ADMIN_RIGHTS['areas'] : { ...AREAS, maintenance: level };
    return {
        status: 200,
        body: meBody(
            'admin',
            'Thomas Keller',
            permissionsJson(areas as Record<string, 'read' | 'write'>, ACTIONS),
        ),
    } as const;
}

const WORKSHOP = { id: 60, name: 'Werkstatt' };
const STORE = { id: 70, name: 'Hauptlager' };

// Feste Uhrzeit (Mittag am heutigen Berliner Kalendertag): „seit 5 Std.“ gilt nur am selben Kalendertag,
// vor 05:00 Ortszeit läge „vor 5 Stunden“ am Vortag („seit 1 Tag“, nächtliche CI 11.11.2026). Die Seite
// bekommt dieselbe Zeit über page.clock.
const BERLIN_TODAY = new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Berlin' }).format(
    new Date(),
);
const NOW = Date.parse(`${BERLIN_TODAY}T11:00:00Z`);
const ago = (hours: number): string =>
    new Date(NOW - hours * 3_600_000).toISOString().slice(0, 19) + 'Z';

interface Entry {
    id: number;
    version: number;
}

/** Zustandsbehafteter Server: bucht wie MaintenanceMoveHandler (Version + 1, Fehler je Gerät). */
function maintenanceServer(
    options: { workshop?: boolean; store?: boolean; defectiveInWorkshop?: boolean } = {},
) {
    const hasWorkshop = options.workshop ?? true;
    const hasStore = options.store ?? true;
    let devices = [
        deviceJson(1, {
            identifier: 'M1',
            location: { id: 50, name: 'Sammelstelle', path: 'Sammelstelle' },
            status: 'defective',
            status_label: 'defekt',
            state_changed_at: ago(72),
        }),
        deviceJson(2, {
            identifier: 'M2',
            location: { id: 51, name: 'Kiste', path: 'Sammelstelle / Kiste' },
            status: 'used',
            status_label: 'benutzt',
            state_changed_at: ago(5),
        }),
        deviceJson(3, {
            identifier: 'EF-1',
            device_type: { id: 2, name: 'Einmalfilter', single_use: true },
            location: { id: 50, name: 'Sammelstelle', path: 'Sammelstelle' },
            status: 'used',
            status_label: 'benutzt',
            state_changed_at: ago(1),
        }),
        deviceJson(4, {
            identifier: 'M4',
            location: { id: 60, name: 'Werkstatt', path: 'Werkstatt' },
            status: 'maintenance',
            status_label: 'in Wartung',
            state_changed_at: ago(30),
        }),
        ...(options.defectiveInWorkshop === true
            ? [
                  deviceJson(5, {
                      identifier: 'PA 11',
                      location: { id: 60, name: 'Werkstatt', path: 'Werkstatt' },
                      status: 'defective',
                      status_label: 'defekt',
                      state_changed_at: ago(20),
                  }),
              ]
            : []),
    ];
    const stageOf = (place: number): Record<string, unknown>[] =>
        devices.filter(
            (device) =>
                (device['location'] as { id: number }).id === place ||
                (place === 50 && (device['location'] as { id: number }).id === 51),
        );
    const stage = (label: string, ids: number[], list: Record<string, unknown>[]) => ({
        label,
        locations: ids.length === 0 ? [] : [{ id: ids[0], name: label }],
        total: list.length,
        shown: list.length,
        devices: list,
    });
    const bookAll =
        (target: { id: number; name: string }, status: string, label: string) =>
        (request: MockRequest): MockResponse => {
            const entries = (request.body as { devices: Entry[] }).devices;
            const results = entries.map((entry) => {
                const current = devices.find((device) => device['id'] === entry.id);
                if (current === undefined) {
                    return {
                        id: entry.id,
                        outcome: 'error',
                        replayed: false,
                        device: null,
                        error: { error: 'not_found', message: 'Nicht gefunden.' },
                    };
                }
                if (current['version'] !== entry.version) {
                    return {
                        id: entry.id,
                        outcome: 'error',
                        replayed: false,
                        device: fullDevice(current),
                        error: { error: 'version_conflict', message: 'Geändert.' },
                    };
                }
                const next = {
                    ...current,
                    version: entry.version + 1,
                    status,
                    status_label: label,
                    location: { id: target.id, name: target.name, path: target.name },
                };
                devices = devices.map((device) => (device['id'] === entry.id ? next : device));
                return {
                    id: entry.id,
                    outcome: 'applied',
                    replayed: false,
                    device: fullDevice(next),
                    error: null,
                };
            });
            const errors = results.filter((result) => result.outcome === 'error').length;
            return {
                status: 200,
                body: {
                    results,
                    summary: {
                        total: results.length,
                        applied: results.length - errors,
                        unchanged: 0,
                        errors,
                    },
                    target,
                },
            };
        };
    return {
        bump: (id: number): void => {
            devices = devices.map((device) =>
                device['id'] === id
                    ? { ...device, version: Number(device['version']) + 1 }
                    : device,
            );
        },
        api: {
            'GET api/v1/maintenance': (): MockResponse => ({
                status: 200,
                body: {
                    collection: stage('Sammelstelle', [50], stageOf(50)),
                    workshop: stage(
                        'Werkstatt',
                        hasWorkshop ? [60] : [],
                        hasWorkshop ? stageOf(60) : [],
                    ),
                    default_store: hasStore ? STORE : null,
                },
            }),
            'POST api/v1/maintenance/to-workshop': bookAll(WORKSHOP, 'maintenance', 'in Wartung'),
            'POST api/v1/maintenance/back': bookAll(STORE, 'ready', 'einsatzbereit'),
        } as Record<string, (request: MockRequest) => MockResponse>,
    };
}

let server = maintenanceServer();

test.beforeEach(() => {
    server = maintenanceServer();
});

async function copied(page: Page): Promise<string[]> {
    return page.evaluate(() => (window as unknown as { __copied: string[] }).__copied);
}

test.describe('Wartung mit Recht „Ändern“', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test.beforeEach(async ({ page }) => {
        await page.clock.install({ time: NOW });
        await page.addInitScript(() => {
            const list: string[] = [];
            Object.assign(window, { __copied: list });
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: {
                    writeText: (text: string) => {
                        list.push(text);
                        return Promise.resolve();
                    },
                },
            });
        });
        await page.setViewportSize({ width: 1280, height: 800 });
    });

    for (const width of [390, 768, 1024]) {
        test(`Wartung (Runde 14): ${String(width)} px alle Bedienelemente erreichbar`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/firefighting/test/wartung');
            await expect(page.getByTestId('maintenance-device').first()).toBeVisible();
            expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
            expect(await unreachableControls(page, '[data-testid^="stage-"]')).toEqual([]);
            expect(await unreachableControls(page, '[data-testid="maintenance-device"]')).toEqual(
                [],
            );
            // U3: Kästchen und „Alle auswählen“ sind mindestens 44 px große Flächen.
            expect(await smallTargets(page, '[data-testid^="stage-"]')).toEqual([]);
        });
    }

    test('Menü, beide Stufen, am längsten wartende zuerst, Einmalgerät nicht wählbar', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/wartung');
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Wartung' }),
        ).toBeVisible();
        await expect(page.getByRole('heading', { level: 1, name: 'Wartung' })).toBeVisible();

        const collection = page.getByTestId('stage-collection');
        await expect(collection.getByRole('heading', { level: 2 })).toContainText('Sammelstelle');
        await expect(collection.getByTestId('maintenance-device')).toHaveCount(3);
        // Sortiert wird vom Server; die Seite zeigt, seit wann (M1 3 Tage, M2 5 Std.).
        await expect(collection.getByTestId('maintenance-device').first()).toContainText('M1');
        await expect(collection.getByTestId('maintenance-device').first()).toContainText(
            'seit 3 Tagen',
        );
        await expect(collection.getByTestId('maintenance-device').nth(1)).toContainText(
            'seit 5 Std.',
        );
        await expect(collection.getByLabel('EF-1 auswählen')).toBeDisabled();
        await expect(collection).toContainText('Einmalgerät');

        const workshop = page.getByTestId('stage-workshop');
        await expect(workshop.getByTestId('maintenance-device')).toHaveCount(1);
        await expect(workshop.getByTestId('maintenance-device')).toContainText('M4');
    });

    test('Zur Werkstatt: Auswahl, Rückfrage, Ergebnis, die Stufen laden neu', async ({
        page,
        apiRequests,
    }) => {
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        const button = collection.getByTestId('to-workshop');
        await expect(button).toBeDisabled();
        await collection
            .getByLabel('Alle angezeigten Ausrüstungen in „Sammelstelle“ auswählen')
            .check();
        await expect(collection.getByLabel('EF-1 auswählen')).not.toBeChecked();
        await expect(button).toContainText('(2)');
        await button.click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm).toContainText('2 Ausrüstungen in die Werkstatt bringen?');
        await expect(confirm).toContainText(
            'Sie stehen dann in „Werkstatt“ mit dem Status „in Wartung“.',
        );
        await confirm.getByRole('button', { name: 'In die Werkstatt bringen' }).click();

        await expect(page.getByTestId('maintenance-result')).toHaveText(
            'Zur Werkstatt: 2 Ausrüstungen geändert.',
        );
        await expect(collection.getByTestId('maintenance-device')).toHaveCount(1);
        await expect(
            page.getByTestId('stage-workshop').getByTestId('maintenance-device'),
        ).toHaveCount(3);
        const sent = apiRequests.filter((request) => request.method === 'POST');
        expect(sent.map((request) => [request.path, request.body])).toEqual([
            [
                'api/v1/maintenance/to-workshop',
                {
                    devices: [
                        { id: 1, version: 1 },
                        { id: 2, version: 1 },
                    ],
                },
            ],
        ]);
    });

    test('ein veraltetes Gerät bleibt ausgewählt und wird genannt', async ({ page }) => {
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        await collection.getByLabel('M1 auswählen').check();
        await collection.getByLabel('M2 auswählen').check();
        server.bump(2);
        await collection.getByTestId('to-workshop').click();
        await page
            .getByTestId('confirm-dialog')
            .getByRole('button', { name: 'In die Werkstatt bringen' })
            .click();

        await expect(page.getByTestId('maintenance-result')).toHaveText(
            'Zur Werkstatt: 1 Ausrüstung geändert, 1 nicht möglich.',
        );
        await expect(page.getByTestId('maintenance-failures')).toContainText(
            'M2: Inzwischen geändert – bitte prüfen und erneut senden.',
        );
        await expect(collection.getByLabel('M2 auswählen')).toBeChecked();
    });

    test('Zurück ins Lager', async ({ page, apiRequests }) => {
        await page.goto('/firefighting/test/wartung');
        const workshop = page.getByTestId('stage-workshop');
        await workshop.getByLabel('M4 auswählen').check();
        await workshop.getByTestId('back').click();
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            '1 Ausrüstung ins Lager bringen?',
        );
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            'Sie steht dann in „Hauptlager“ mit dem Status „einsatzbereit“.',
        );
        await page
            .getByTestId('confirm-dialog')
            .getByRole('button', { name: 'Ins Lager bringen' })
            .click();

        await expect(page.getByTestId('maintenance-result')).toHaveText(
            'Zurück ins Lager: 1 Ausrüstung geändert.',
        );
        await expect(workshop.getByTestId('maintenance-device')).toHaveCount(0);
        expect(
            apiRequests
                .filter((request) => request.method === 'POST')
                .map((request) => request.path),
        ).toEqual(['api/v1/maintenance/back']);
    });

    test('Transportliste kopieren: die Auswahl, ohne Auswahl alle', async ({ page }) => {
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        await collection.getByTestId('copy-collection').click();
        // U5: Rückmeldung direkt am Knopf mit der Anzahl, verschwindet nach wenigen Sekunden.
        await expect(collection.getByTestId('copy-note-collection')).toHaveText(
            '✓ Liste kopiert (3 Ausrüstungen)',
        );
        await page.clock.runFor(5000);
        await expect(collection.getByTestId('copy-note-collection')).toHaveCount(0);
        await collection.getByLabel('M2 auswählen').check();
        await collection.getByTestId('copy-collection').click();
        await expect
            .poll(() => copied(page))
            .toEqual([
                'Sammelstelle (3)\nM1 · Atemschutzmaske · defekt · Sammelstelle\nM2 · Atemschutzmaske · benutzt · Sammelstelle / Kiste\nEF-1 · Einmalfilter · benutzt · Sammelstelle',
                'Sammelstelle (1)\nM2 · Atemschutzmaske · benutzt · Sammelstelle / Kiste',
            ]);
    });
});

test.describe('Wartung: Rückfragen und defekte Ausrüstung (U5, U10)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test.beforeEach(async ({ page }) => {
        server = maintenanceServer({ defectiveInWorkshop: true });
        await page.clock.install({ time: NOW });
        await page.addInitScript(() => {
            const list: string[] = [];
            Object.assign(window, { __copied: list });
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: (text: string) => Promise.resolve(void list.push(text)) },
            });
        });
        await page.setViewportSize({ width: 390, height: 900 });
    });

    test('U5: Reihenfolge genannt, im Fenster „Transportliste vorher kopieren?“ mit Rückmeldung', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        await expect(collection).toContainText(
            'erst „Transportliste kopieren“, dann „In die Werkstatt bringen“',
        );
        await collection.getByLabel('M2 auswählen').check();
        await collection.getByTestId('to-workshop').click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm).toContainText('Transportliste vorher kopieren?');
        await confirm.getByTestId('confirm-copy').click();
        await expect(confirm).toContainText('✓ Liste kopiert (1 Ausrüstung)');
        await expect
            .poll(() => copied(page))
            .toEqual(['Sammelstelle (1)\nM2 · Atemschutzmaske · benutzt · Sammelstelle / Kiste']);
    });

    test('U10: „Alle auswählen“ lässt Defektes in der Werkstatt aus, Warnung bei Handauswahl', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/wartung');
        const workshop = page.getByTestId('stage-workshop');
        await expect(workshop.getByTestId('stays-in-workshop')).toHaveText(
            'defekt – bleibt in der Werkstatt',
        );
        await workshop.getByLabel('Alle angezeigten Ausrüstungen in „Werkstatt“ auswählen').check();
        await expect(workshop.getByLabel('M4 auswählen')).toBeChecked();
        await expect(workshop.getByLabel('PA 11 auswählen')).not.toBeChecked();
        await expect(workshop.getByTestId('back')).toContainText('Ins Lager bringen …');
        await expect(workshop.getByTestId('back')).toContainText('(1)');
        // Von Hand gewählt: die Rückfrage warnt.
        await workshop.getByLabel('PA 11 auswählen').check();
        await workshop.getByTestId('back').click();
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            'Achtung: PA 11 ist noch defekt und gilt danach als einsatzbereit.',
        );
        await page
            .getByTestId('confirm-dialog')
            .getByRole('button', { name: 'Ins Lager bringen' })
            .click();
    });
});

test.describe('ohne Werkstatt und ohne Lager', () => {
    test.beforeEach(() => {
        server = maintenanceServer({ workshop: false, store: false });
    });

    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test('Hinweise statt Knöpfe, die gehen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        await collection.getByLabel('M1 auswählen').check();
        await expect(collection.getByTestId('to-workshop')).toBeDisabled();
        await expect(collection).toContainText('Es gibt noch keine Werkstatt.');
        await expect(page.getByTestId('stage-workshop').getByTestId('stage-empty')).toContainText(
            'Hier ist noch kein Standort eingerichtet.',
        );
    });
});

test.describe('nur Ansehen', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin('read'),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test('keine Kästchen und keine Sammelaktionen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/wartung');
        const collection = page.getByTestId('stage-collection');
        await expect(collection.getByTestId('maintenance-device')).toHaveCount(3);
        await expect(collection.getByRole('checkbox')).toHaveCount(0);
        await expect(collection.getByTestId('to-workshop')).toHaveCount(0);
        await expect(collection.getByTestId('copy-collection')).toBeVisible();
    });
});

test.describe('Stadt ohne Sammelstelle und Werkstatt', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(null),
            ...ADMIN_OVERVIEW,
        },
    });

    test('kein Menüeintrag, die Adresse führt zur Startseite', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/firefighting/test/dashboard');
        await expect(page.getByRole('heading', { name: 'Dashboard', level: 1 })).toBeVisible();
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: 'Wartung' }),
        ).toHaveCount(0);
        await page.goto('/firefighting/test/wartung');
        await expect(page).toHaveURL(/\/firefighting\/test\/dashboard$/);
    });
});
