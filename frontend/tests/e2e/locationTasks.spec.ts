import type { Locator, Page } from '@playwright/test';
import {
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    test,
    type MockRequest,
    type MockResponse,
} from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { delegate } from './support/masterData';
import { serviceServer, type ServiceServer } from './support/service';

/**
 * Aufgaben von Standorten (Phase 2, P2.2): Reiter „Abläufe“ des Super-Admins (freischalten,
 * umbenennen, Abschalten bei Verwendung) und die Aufgabe im Fenster „Bearbeiten“ des Admins.
 * Attrappen je Test neu.
 */

const IDS = ['store', 'collection', 'workshop', 'returns', 'borrower'] as const;
const DEFAULT_LABELS = ['Lager', 'Sammelstelle', 'Werkstatt', 'Rückgabe', 'Ausgeliehen an'];

interface TaskState {
    enabled: boolean;
    label: string | null;
    locations: number;
}

function taskJson(states: Record<string, TaskState>): Record<string, unknown> {
    return {
        tasks: IDS.map((task, index) => ({
            task,
            label: states[task]?.label ?? DEFAULT_LABELS[index],
            default_label: DEFAULT_LABELS[index],
            enabled: states[task]?.enabled ?? false,
            locations: states[task]?.locations ?? 0,
        })),
    };
}

let states: Record<string, TaskState> = {};
let service: ServiceServer = serviceServer();
let rejectOff = false;

function tasksApi(): Record<string, (request: MockRequest) => MockResponse> {
    return {
        'GET api/v1/tenants/2/location-tasks': () => ({ status: 200, body: taskJson(states) }),
        'POST api/v1/tenants/2/location-tasks': (request) => {
            const entries = (
                request.body as {
                    tasks: { task: string; enabled: boolean; label: string | null }[];
                }
            ).tasks;
            if (rejectOff && entries.some((entry) => !entry.enabled)) {
                return {
                    status: 409,
                    body: {
                        error: 'rule_violation',
                        message: 'Die Aufgabe „Lager“ wird noch von 1 Standort(en) verwendet.',
                        details: { locations: 1 },
                    },
                };
            }
            for (const entry of entries) {
                states[entry.task] = {
                    enabled: entry.enabled,
                    label: entry.label,
                    locations: states[entry.task]?.locations ?? 0,
                };
            }
            return { status: 200, body: taskJson(states) };
        },
    };
}

test.beforeEach(() => {
    states = {};
    service = serviceServer();
    rejectOff = false;
});

test.describe('Reiter „Abläufe“ (Super-Admin)', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('superadmin', 'Holger') },
            ...delegate(() => ({ ...service.handlers, ...tasksApi() })),
        },
    });

    test('freischalten, umbenennen und speichern', async ({ page, apiRequests }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/service/staedte/2');
        await page.getByRole('tab', { name: 'Abläufe' }).click();
        await expect(page).toHaveURL(/staedte\/2\?ansicht=ablaeufe$/);

        const rows = page.getByTestId('task-row');
        await expect(rows).toHaveCount(5);
        await expect(rows.nth(1)).toContainText('Sammelstelle');
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeDisabled();

        await rows.nth(0).getByRole('checkbox').check();
        await rows.nth(1).getByRole('checkbox').check();
        await page.getByLabel('Name für Sammelstelle').fill('Kiste');
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled();
        await page.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByText('Die Abläufe sind gespeichert.')).toBeVisible();
        const sent = apiRequests.find((request) => request.method === 'POST');
        expect(sent?.body).toEqual({
            tasks: [
                { task: 'store', enabled: true, label: null },
                { task: 'collection', enabled: true, label: 'Kiste' },
                { task: 'workshop', enabled: false, label: null },
                { task: 'returns', enabled: false, label: null },
                { task: 'borrower', enabled: false, label: null },
            ],
        });
        await expect(page.getByLabel('Name für Sammelstelle')).toHaveValue('Kiste');
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeDisabled();
    });

    test('zu langer Name sperrt das Speichern', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/service/staedte/2?ansicht=ablaeufe');
        await page.getByLabel('Name für Lager').fill('x'.repeat(51));
        await expect(page.getByText('Höchstens 50 Zeichen.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeDisabled();
    });

    test.describe('Abschalten bei Verwendung', () => {
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('der Server lehnt ab, die Meldung steht da', async ({ page }) => {
            states = { store: { enabled: true, label: null, locations: 1 } };
            rejectOff = true;
            await page.setViewportSize({ width: 1280, height: 900 });
            await page.goto('/firefighting/service/staedte/2?ansicht=ablaeufe');
            await expect(page.getByText('Von 1 Standort verwendet')).toBeVisible();
            await page.getByTestId('task-row').nth(0).getByRole('checkbox').uncheck();
            await page.getByRole('button', { name: 'Speichern' }).click();
            await expect(page.getByText('wird noch von 1 Standort(en) verwendet')).toBeVisible();
        });
    });
});

test.describe('Aufgabe im Fenster „Bearbeiten“ (Admin)', () => {
    let location: Record<string, unknown>;
    const posted: unknown[] = [];

    function locationJson(): Record<string, unknown> {
        return {
            id: 10,
            name: 'Gerätehaus',
            kind: 'room',
            kind_label: 'Raum/Lager',
            level: 1,
            parent_id: null,
            active: true,
            selectable: true,
            task: null,
            is_default_store: false,
            collection_accepts: null,
            device_count: 0,
            device_count_total: 0,
            children: [],
            created_at: '2026-10-01T08:00:00Z',
            updated_at: '2026-10-01T08:00:00Z',
            ...location,
        };
    }

    test.beforeEach(() => {
        location = {};
        posted.length = 0;
    });

    test.use({
        api: {
            'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
            ...ADMIN_OVERVIEW,
            'GET api/v1/device-types': { status: 200, body: { device_types: [] } },
            'GET api/v1/locations': () => ({ status: 200, body: { locations: [locationJson()] } }),
            'GET api/v1/location-tasks': () => ({
                status: 200,
                body: taskJson({
                    store: { enabled: true, label: null, locations: 0 },
                    collection: { enabled: true, label: 'Kiste', locations: 0 },
                }),
            }),
            'POST api/v1/locations/10/task': (request) => {
                posted.push(request.body);
                const sent = request.body as {
                    task: string | null;
                    is_default_store?: boolean;
                    collection_accepts?: string;
                };
                location = {
                    task: sent.task,
                    is_default_store: sent.task === 'store',
                    collection_accepts: sent.task === 'collection' ? sent.collection_accepts : null,
                };
                return { status: 200, body: { location: locationJson() } };
            },
        },
    });

    function dialog(page: Page): Locator {
        return page.getByTestId('form-dialog');
    }

    test('Aufgabe wählen, Standard-Lager, Name in der Zeile', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/standorte');
        await page.getByRole('button', { name: 'Gerätehaus bearbeiten' }).click();
        const fieldset = page.getByTestId('task-fieldset');
        await expect(fieldset.getByRole('radio')).toHaveCount(3);
        await expect(fieldset.getByLabel('Keine Aufgabe')).toBeChecked();
        await expect(fieldset.getByLabel('Kiste')).toBeVisible();

        await fieldset.getByLabel('Lager', { exact: true }).check();
        await expect(fieldset.getByLabel(/Standard-Lager/)).toBeVisible();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('task-chip')).toHaveText('Standard-Lager');
        expect(posted).toEqual([
            { task: 'store', is_default_store: false, collection_accepts: 'both' },
        ]);
    });

    test('Sammelstelle mit Annahme; Aufgabe entfernen', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto('/firefighting/test/standorte');
        await page.getByRole('button', { name: 'Gerätehaus bearbeiten' }).click();
        const fieldset = page.getByTestId('task-fieldset');
        await fieldset.getByLabel('Kiste').check();
        await fieldset.getByLabel('nur defekte').check();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('task-chip')).toHaveText('Kiste');

        await page.getByRole('button', { name: 'Gerätehaus bearbeiten' }).click();
        await expect(page.getByTestId('task-fieldset').getByLabel('nur defekte')).toBeChecked();
        await page.getByTestId('task-fieldset').getByLabel('Keine Aufgabe').check();
        await dialog(page).getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('task-chip')).toHaveCount(0);
        expect(posted).toEqual([
            { task: 'collection', is_default_store: false, collection_accepts: 'defective' },
            { task: null, is_default_store: false, collection_accepts: 'defective' },
        ]);
    });
});
