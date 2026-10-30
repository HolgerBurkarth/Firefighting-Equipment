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
import { delegate, deviceTypeServer, type TestDeviceType } from './support/masterData';

const TYPES: readonly TestDeviceType[] = [
    { id: 1, name: 'Atemschutzgerät', count: 12 },
    { id: 2, name: 'Atemschutzmaske', count: 40 },
    { id: 3, name: 'Funkgerät', count: 12 },
    { id: 4, name: 'Wärmebildkamera', count: 2, active: false },
];

let override: (request: MockRequest) => MockResponse | null = () => null;
let server = deviceTypeServer(TYPES, (request) => override(request));

test.beforeEach(() => {
    override = () => null;
    server = deviceTypeServer(TYPES, (request) => override(request));
});

test.use({
    api: {
        'GET api/v1/auth/me': { status: 200, body: meBody('admin', 'Thomas Keller') },
        ...ADMIN_OVERVIEW,
        ...delegate(() => server),
    },
});

/** 409 name_taken: Der Server kennt „Drehleiter“ schon (z. B. gerade von einem anderen Admin). */
function rejectDrehleiter(request: MockRequest): MockResponse | null {
    return (request.body as { name?: unknown }).name === 'Drehleiter'
        ? {
              status: 409,
              body: {
                  error: 'name_taken',
                  message: 'Es gibt bereits einen Gerätetyp „Drehleiter“.',
              },
          }
        : null;
}

function names(page: Page): Locator {
    return page.getByTestId('device-type-table').locator('.type-name');
}

function dialog(page: Page): Locator {
    return page.getByTestId('form-dialog');
}

async function open(page: Page): Promise<void> {
    await page.goto('/firefighting/test/geraetetypen');
    await expect(page.getByRole('heading', { level: 1, name: 'Gerätetypen' })).toBeVisible();
    await expect(page.getByTestId('device-type-table')).toBeVisible();
}

test.describe('Gerätetypen (PC)', () => {
    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Liste nach Name, Spaltenköpfe sortieren, deaktivierte auf Wunsch', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        expect(
            apiRequests.some((request) => request.url.endsWith('/device-types?include_inactive=1')),
        ).toBe(true);
        await expect(
            page.getByRole('navigation', { name: 'Hauptmenü' }).getByRole('link', {
                name: 'Gerätetypen',
            }),
        ).toHaveAttribute('aria-current', 'page');
        await expect(names(page)).toHaveText(['Atemschutzgerät', 'Atemschutzmaske', 'Funkgerät']);
        await expect(page.getByTestId('device-type-2')).toContainText('40 Geräte');
        await expect(page.getByRole('columnheader', { name: 'Name' })).toHaveAttribute(
            'aria-sort',
            'ascending',
        );
        // Am PC sortieren die Spaltenköpfe, die Sortier-Knöpfe gibt es nur am Handy.
        await expect(page.getByRole('group', { name: 'Sortieren' })).toBeHidden();

        const count = page.getByRole('button', { name: 'Geräte', exact: true });
        await count.click();
        await expect(names(page)).toHaveText(['Atemschutzgerät', 'Funkgerät', 'Atemschutzmaske']);
        await expect(page.getByRole('columnheader', { name: 'Geräte' })).toHaveAttribute(
            'aria-sort',
            'ascending',
        );
        await count.click();
        await expect(names(page)).toHaveText(['Atemschutzmaske', 'Atemschutzgerät', 'Funkgerät']);
        await expect(page.getByRole('columnheader', { name: 'Geräte' })).toHaveAttribute(
            'aria-sort',
            'descending',
        );
        await expect(page.getByRole('columnheader', { name: 'Name' })).toHaveAttribute(
            'aria-sort',
            'none',
        );

        await page.getByLabel('Deaktivierte anzeigen (1)').check();
        await expect(page.getByTestId('device-type-4')).toContainText('deaktiviert');
        await page.getByRole('searchbox', { name: 'Gerätetypen filtern' }).fill('warme');
        await expect(names(page)).toHaveText(['Wärmebildkamera']);
    });

    test('anlegen und umbenennen; gleicher Name fällt beim Tippen auf', async ({
        page,
        apiRequests,
    }) => {
        await open(page);
        await page.getByRole('button', { name: 'Neuer Gerätetyp' }).click();
        const form = dialog(page);
        await expect(form.getByRole('heading', { name: 'Neuer Gerätetyp' })).toBeVisible();
        const name = form.getByLabel('Name');
        await name.fill('funkgerät');
        await expect(form.getByText('Diesen Gerätetyp gibt es schon.')).toBeVisible();
        await expect(form.getByRole('button', { name: 'Anlegen' })).toBeDisabled();
        await name.fill('x'.repeat(51));
        await expect(form.getByText('Höchstens 50 Zeichen (jetzt 51).')).toBeVisible();

        await name.fill('Hitzeschutzanzug');
        await form.getByRole('button', { name: 'Anlegen' }).click();
        await expect(form).toBeHidden();
        await expect(page.getByTestId('success-notice')).toHaveText(
            '„Hitzeschutzanzug“ wurde angelegt.',
        );
        await expect(names(page)).toHaveText([
            'Atemschutzgerät',
            'Atemschutzmaske',
            'Funkgerät',
            'Hitzeschutzanzug',
        ]);

        await page.getByRole('button', { name: 'Funkgerät bearbeiten' }).click();
        await expect(form.getByLabel('Name')).toHaveValue('Funkgerät');
        await form.getByLabel('Name').fill('Handfunkgerät');
        await form.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            '„Funkgerät“ heißt jetzt „Handfunkgerät“.',
        );
        await expect(page.getByTestId('device-type-3')).toContainText('Handfunkgerät');
        expect(
            apiRequests
                .filter((request) => request.method === 'POST')
                .map((request) => [request.path, request.body]),
        ).toEqual([
            ['api/v1/device-types', { name: 'Hitzeschutzanzug' }],
            ['api/v1/device-types/3/rename', { name: 'Handfunkgerät' }],
        ]);
    });

    test('deaktivieren mit Rückfrage, wieder aktivieren', async ({ page, apiRequests }) => {
        await open(page);
        const form = dialog(page);
        await page.getByRole('button', { name: 'Funkgerät bearbeiten' }).click();
        await form.getByRole('button', { name: 'Funkgerät deaktivieren' }).click();
        const confirm = page.getByTestId('confirm-dialog');
        await expect(
            confirm.getByRole('heading', { name: 'Funkgerät deaktivieren?' }),
        ).toBeVisible();
        await expect(confirm).toContainText(
            'Neue Geräte können „Funkgerät“ dann nicht mehr bekommen. Die 12 Geräte dieses Typs behalten ihn.',
        );
        await expect(confirm.getByRole('button', { name: 'Abbrechen' })).toBeFocused();
        await confirm.getByRole('button', { name: 'Deaktivieren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText('„Funkgerät“ ist deaktiviert.');
        await expect(names(page)).toHaveText(['Atemschutzgerät', 'Atemschutzmaske']);

        await page.getByLabel('Deaktivierte anzeigen (2)').check();
        await page.getByRole('button', { name: 'Funkgerät bearbeiten' }).click();
        await form.getByRole('button', { name: 'Funkgerät aktivieren' }).click();
        await expect(page.getByTestId('success-notice')).toHaveText(
            '„Funkgerät“ ist wieder aktiv.',
        );
        await expect(page.getByTestId('device-type-3')).not.toContainText('deaktiviert');
        expect(
            apiRequests.filter((request) => request.method === 'POST').map((r) => r.path),
        ).toEqual(['api/v1/device-types/3/deactivate', 'api/v1/device-types/3/activate']);
    });

    test.describe('Name vom Server abgelehnt', () => {
        test.use({ allowedConsoleError: HTTP_ERROR_CONSOLE });

        test('Meldung am Feld, das Fenster bleibt offen', async ({ page }) => {
            override = rejectDrehleiter;
            await open(page);
            await page.getByRole('button', { name: 'Neuer Gerätetyp' }).click();
            const form = dialog(page);
            await form.getByLabel('Name').fill('Drehleiter');
            await form.getByRole('button', { name: 'Anlegen' }).click();
            await expect(
                form.getByText('Es gibt bereits einen Gerätetyp „Drehleiter“.'),
            ).toBeVisible();
            await expect(form.getByLabel('Name')).toHaveAttribute('aria-invalid', 'true');
        });
    });
});

test('Handy: Karten mit Sortier-Knöpfen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await open(page);
    await expect(page.getByRole('columnheader', { name: 'Name' })).toBeHidden();
    const sort = page.getByRole('group', { name: 'Sortieren' });
    await expect(sort.getByRole('button', { name: 'Name' })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    const count = sort.getByRole('button', { name: 'Geräte' });
    await count.click();
    await expect(count).toHaveAttribute('aria-pressed', 'true');
    await expect(names(page)).toHaveText(['Atemschutzgerät', 'Funkgerät', 'Atemschutzmaske']);
    await count.click();
    await expect(names(page)).toHaveText(['Atemschutzmaske', 'Atemschutzgerät', 'Funkgerät']);
    expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
    const edit = page.getByRole('button', { name: 'Atemschutzmaske bearbeiten' });
    const box = await edit.boundingBox();
    expect(Math.round(box?.height ?? 0)).toBeGreaterThanOrEqual(44);
});
