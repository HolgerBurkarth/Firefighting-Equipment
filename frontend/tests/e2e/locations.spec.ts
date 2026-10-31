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
import { horizontalOverflow } from './support/layout';
import { delegate, locationServer, type TestLocation } from './support/masterData';

/**
 * HLF 20 (10) mit G1 (11, 6 Geräte), G2 (12, leer) und G3 (13, gesperrt); LF 10 (20, 2 Geräte
 * ohne Fach); Lager (30, Raum, 5); Altes Lager (40, Raum, gesperrt) mit Regal (41, gesperrt).
 */
const TREE: readonly TestLocation[] = [
    {
        id: 10,
        name: 'HLF 20',
        children: [
            { id: 11, name: 'G1', count: 6 },
            { id: 12, name: 'G2' },
            { id: 13, name: 'G3', active: false },
        ],
    },
    { id: 20, name: 'LF 10', own: 2 },
    { id: 30, name: 'Lager', kind: 'room', own: 5 },
    {
        id: 40,
        name: 'Altes Lager',
        kind: 'room',
        active: false,
        children: [{ id: 41, name: 'Regal', active: false }],
    },
];

/** Antworten, die den simulierten Server in einzelnen Tests ersetzen (z. B. 409). */
let override: (request: MockRequest) => MockResponse | null = () => null;
let server = locationServer(TREE, (request) => override(request));

/** 409 name_taken für ein neues Fach „G4“ (der Server kennt einen Namen, den der Baum nicht zeigt). */
function rejectFachG4(request: MockRequest): MockResponse | null {
    const name = (request.body as { name?: unknown }).name;
    return request.method === 'POST' && name === 'G4'
        ? {
              status: 409,
              body: {
                  error: 'name_taken',
                  message: 'Unter „HLF 20“ gibt es bereits einen Standort „G4“.',
              },
          }
        : null;
}

/** 409 beim Sperren von G2: Inzwischen liegt dort ein Gerät (der Baum ist veraltet). */
function lockStillFull(request: MockRequest): MockResponse | null {
    return request.path === 'api/v1/locations/12/lock'
        ? {
              status: 409,
              body: {
                  error: 'rule_violation',
                  message:
                      'Der Standort „G2“ ist nicht leer (1 Gerät). Bitte zuerst die Geräte umbuchen bzw. die Unterstandorte sperren.',
                  details: { devices: 1, sublocations: 0 },
              },
          }
        : null;
}

test.beforeEach(() => {
    override = () => null;
    server = locationServer(TREE, (request) => override(request));
});

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...delegate(() => server.api),
    },
});

function node(page: Page, id: number): Locator {
    return page.getByTestId(`location-${String(id)}`);
}

function dialog(page: Page): Locator {
    return page.getByTestId('form-dialog');
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/standorte');
    await expect(page.getByRole('heading', { level: 1, name: 'Standorte' })).toBeVisible();
    await expect(page.getByTestId('location-tree')).toBeVisible();
}

test.describe('Standorte (PC)', () => {
    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Baum mit Art, Gerätezahlen und Fächern; gesperrte auf Wunsch', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        const nav = page.getByRole('navigation', { name: 'Hauptmenü' });
        await expect(nav.getByRole('link', { name: 'Standorte' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            apiRequests.some((request) => request.url.endsWith('/locations?include_inactive=1')),
        ).toBe(true);

        const tree = page.getByTestId('location-tree');
        await expect(tree.locator('.node-name')).toHaveText([
            'HLF 20',
            'G1',
            'G2',
            'LF 10',
            'Lager',
        ]);
        await expect(node(page, 10).locator('.node-meta').first()).toHaveText(
            'Fahrzeug · 6 Geräte',
        );
        await expect(node(page, 11).locator('.node-meta')).toHaveText('6 Geräte');
        await expect(node(page, 20).locator('.node-meta')).toHaveText('Fahrzeug · 2 Geräte');
        await expect(node(page, 30).locator('.node-meta')).toHaveText('Raum/Lager · 5 Geräte');
        await expect(node(page, 13)).toHaveCount(0);

        await page.getByLabel('Gesperrte anzeigen (3)').check();
        await expect(tree.locator('.node-name')).toHaveText([
            'HLF 20',
            'G1',
            'G2',
            'G3',
            'LF 10',
            'Lager',
            'Altes Lager',
            'Regal',
        ]);
        await expect(node(page, 13).getByText('gesperrt')).toBeVisible();
        // Ein gesperrter Standort bekommt kein neues Fach.
        await expect(page.getByRole('button', { name: 'Fach in Altes Lager anlegen' })).toHaveCount(
            0,
        );

        const filter = page.getByRole('searchbox', { name: 'Standorte filtern' });
        await filter.fill('hlf g1');
        await expect(tree.locator('.node-name')).toHaveText(['HLF 20', 'G1']);
        await filter.fill('Drehleiter');
        await expect(page.getByText('Kein Standort passt zu „Drehleiter“.')).toBeVisible();
    });

    test('neuer Standort mit Eingabe: Klick daneben schließt nicht, Escape fragt nach', async ({
        page,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Neuer Standort' }).click();
        const name = dialog(page).getByLabel('Name');
        await name.fill('Werkstatt');
        await page.mouse.click(5, 5);
        await expect(dialog(page)).toBeVisible();
        await expect(name).toHaveValue('Werkstatt');
        await page.keyboard.press('Escape');
        const discard = page.getByRole('alertdialog', { name: 'Eingaben verwerfen?' });
        await expect(discard.getByRole('button', { name: 'Weiter bearbeiten' })).toBeFocused();
        await discard.getByRole('button', { name: 'Verwerfen' }).click();
        await expect(dialog(page)).toHaveCount(0);
    });

    test('neuer Standort mit Art; gleicher Name fällt beim Tippen auf', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Neuer Standort' }).click();
        const form = dialog(page);
        await expect(form.getByRole('heading', { name: 'Neuer Standort' })).toBeVisible();
        const name = form.getByLabel('Name');
        await expect(name).toBeFocused();

        await name.fill('hlf 20');
        await expect(form.getByText('Diesen Namen gibt es hier schon.')).toBeVisible();
        await expect(name).toHaveAttribute('aria-invalid', 'true');
        await expect(form.getByRole('button', { name: 'Anlegen' })).toBeDisabled();

        await name.fill('Werkstatt');
        await form.getByLabel('Raum/Lager').check();
        await form.getByRole('button', { name: 'Anlegen' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByTestId('success-notice')).toHaveText('„Werkstatt“ wurde angelegt.');
        await expect(node(page, 901).locator('.node-meta')).toHaveText('Raum/Lager · 0 Geräte');
        const created = apiRequests.filter((request) => request.method === 'POST');
        expect(created.map((request) => request.body)).toEqual([
            { name: 'Werkstatt', kind: 'room' },
        ]);
    });

    test.describe('Name vom Server abgelehnt', () => {
        // Die Browser melden die 409-Antwort in der Konsole.
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('Fach anlegen; vom Server abgelehnter Name steht am Feld', async ({
            page,
            apiRequests,
        }) => {
            override = rejectFachG4;
            await open(page);
            await page.getByRole('button', { name: 'Fach in HLF 20 anlegen' }).click();
            const form = dialog(page);
            await expect(form.getByRole('heading', { name: 'Neues Fach in HLF 20' })).toBeVisible();
            await expect(
                form.getByText('Fächer gehören zu „HLF 20“ und haben dieselbe Art.'),
            ).toBeVisible();
            // Keine Auswahl der Art: Fächer übernehmen sie.
            await expect(form.getByLabel('Raum/Lager')).toHaveCount(0);

            await form.getByLabel('Name').fill('G4');
            await form.getByRole('button', { name: 'Anlegen' }).click();
            await expect(
                form.getByText('Unter „HLF 20“ gibt es bereits einen Standort „G4“.'),
            ).toBeVisible();

            await form.getByLabel('Name').fill('G5');
            await expect(form.getByText('Unter „HLF 20“ gibt es bereits')).toHaveCount(0);
            await form.getByRole('button', { name: 'Anlegen' }).click();
            await expect(form).toBeHidden();
            await expect(page.getByTestId('success-notice')).toHaveText('„G5“ wurde angelegt.');
            await expect(node(page, 10).locator('.node-child .node-name')).toHaveText([
                'G1',
                'G2',
                'G5',
            ]);
            expect(
                apiRequests.filter((request) => request.method === 'POST').map((r) => r.body),
            ).toEqual([
                { name: 'G4', parent_id: 10 },
                { name: 'G5', parent_id: 10 },
            ]);
        });
    });

    test('umbenennen; unveränderter Name sendet nichts', async ({ page, apiRequests }) => {
        await open(page);
        const edit = page.getByRole('button', { name: 'HLF 20 / G1 bearbeiten' });
        await edit.click();
        const form = dialog(page);
        await expect(form.getByRole('heading', { name: 'G1 bearbeiten' })).toBeVisible();
        await expect(form.getByLabel('Name')).toHaveValue('G1');
        await form.getByRole('button', { name: 'Speichern' }).click();
        await expect(form).toBeHidden();
        await expect(edit).toBeFocused();

        await edit.click();
        await form.getByLabel('Name').fill('Geräteraum 1');
        await form.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            '„G1“ heißt jetzt „Geräteraum 1“.',
        );
        await expect(node(page, 11).locator('.node-name')).toHaveText('Geräteraum 1');
        const posts = apiRequests.filter((request) => request.method === 'POST');
        expect(posts.map((request) => [request.path, request.body])).toEqual([
            ['api/v1/locations/11/rename', { name: 'Geräteraum 1' }],
        ]);
    });

    test('sperren nur leer und mit Rückfrage, entsperren', async ({ page, apiRequests }) => {
        await open(page);
        const form = dialog(page);

        await page.getByRole('button', { name: 'HLF 20 / G1 bearbeiten' }).click();
        await expect(form.getByTestId('lock-blocker')).toHaveText(
            'Sperren geht nur, wenn der Standort leer ist. Hier liegen noch 6 Geräte.',
        );
        await expect(form.getByRole('button', { name: 'G1 sperren' })).toBeDisabled();
        await form.getByRole('button', { name: 'Abbrechen' }).click();

        await page.getByRole('button', { name: 'HLF 20 bearbeiten' }).click();
        await expect(form.getByTestId('lock-blocker')).toHaveText(
            'Sperren geht nur, wenn der Standort leer ist. Hier liegen noch 2 aktive Fächer.',
        );
        await page.keyboard.press('Escape');
        await expect(form).toBeHidden();

        await page.getByRole('button', { name: 'HLF 20 / G2 bearbeiten' }).click();
        await form.getByRole('button', { name: 'G2 sperren' }).click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(confirm.getByRole('heading', { name: 'G2 sperren?' })).toBeVisible();
        await expect(confirm.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await confirm.getByRole('button', { name: 'Sperren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('„G2“ ist gesperrt.');
        await expect(node(page, 12)).toHaveCount(0);

        await page.getByLabel('Gesperrte anzeigen (4)').check();
        await expect(node(page, 12).getByText('gesperrt')).toBeVisible();
        await page.getByRole('button', { name: 'HLF 20 / G2 bearbeiten' }).click();
        await form.getByRole('button', { name: 'G2 entsperren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('„G2“ ist wieder frei.');
        await expect(node(page, 12).getByText('gesperrt')).toHaveCount(0);

        // Fach unter einem gesperrten Standort: erst den Standort entsperren.
        await page.getByRole('button', { name: 'Altes Lager / Regal bearbeiten' }).click();
        await expect(form.getByText('Zuerst „Altes Lager“ entsperren.')).toBeVisible();
        await expect(form.getByRole('button', { name: 'Regal entsperren' })).toBeDisabled();

        expect(
            apiRequests.filter((request) => request.method === 'POST').map((r) => r.path),
        ).toEqual(['api/v1/locations/12/lock', 'api/v1/locations/12/unlock']);
    });

    test.describe('Sperren scheitert am Server', () => {
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('Meldung des Servers, der Baum bleibt', async ({ page }) => {
            override = lockStillFull;
            await open(page);
            await page.getByRole('button', { name: 'HLF 20 / G2 bearbeiten' }).click();
            await dialog(page).getByRole('button', { name: 'G2 sperren' }).click();
            await page
                .getByTestId('confirm-dialog')
                .getByRole('button', { name: 'Sperren' })
                .click();
            await expect(
                page.getByText('Der Standort „G2“ ist nicht leer (1 Gerät).', { exact: false }),
            ).toBeVisible();
            await expect(node(page, 12).getByText('gesperrt')).toHaveCount(0);
        });
    });
});

test('Handy: Baum ohne waagerechtes Scrollen, Knöpfe mindestens 44 px', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page);
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    for (const button of await page.getByTestId('location-tree').getByRole('button').all()) {
        const box = await button.boundingBox();
        expect(Math.round(box?.width ?? 0)).toBeGreaterThanOrEqual(44);
        expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
    }
    await page.getByRole('button', { name: 'Neuer Standort' }).click();
    const box = await dialog(page).boundingBox();
    expect(box?.x ?? -1).toBeGreaterThanOrEqual(0);
    expect((box?.x ?? 0) + (box?.width ?? 1000)).toBeLessThanOrEqual(390);
});
