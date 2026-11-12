import { ADMIN_RIGHTS, expect, meBody, permissionsJson, test } from './fixtures';
import type { MockRequest, MockResponse } from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { horizontalOverflow, unreachableControls } from './support/layout';
import { delegate } from './support/masterData';

/**
 * Sollbestand auf der Seite „Standorte“ (Phase 2, P2.7): Regeln je Standort mit Soll, Ist und Fehlendem,
 * „außer Betrieb“ (vom Server berechnet), Fenster „Sollbestand festlegen“ mit mehreren gleichwertigen Typen.
 */

const TYPES = [
    { id: 1, name: 'Atemschutzmaske', active: true, device_count: 5 },
    { id: 2, name: 'Funkgerät', active: true, device_count: 2 },
    { id: 3, name: 'Alter Typ', active: false, device_count: 0 },
];

const LOCATIONS = [
    {
        id: 10,
        name: 'HLF 20',
        kind: 'vehicle',
        kind_label: 'Fahrzeug',
        level: 1,
        parent_id: null,
        active: true,
        selectable: true,
        task: null,
        is_default_store: false,
        collection_accepts: null,
        device_count: 0,
        device_count_total: 0,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        children: [
            {
                id: 11,
                name: 'G1',
                kind: 'vehicle',
                kind_label: 'Fahrzeug',
                level: 2,
                parent_id: 10,
                active: true,
                selectable: true,
                device_count: 0,
                created_at: '2026-10-01T08:00:00Z',
                updated_at: '2026-10-01T08:00:00Z',
            },
        ],
    },
];

interface Rule {
    id: number;
    min_count: number;
    types: { id: number; name: string }[];
    have: number;
    missing: number;
}

function stockServer(options: { have?: number } = {}) {
    let rules: Rule[] = [
        {
            id: 1,
            min_count: 3,
            types: [{ id: 1, name: 'Atemschutzmaske' }],
            have: 2,
            missing: 1,
        },
    ];
    const posted: unknown[] = [];
    const entry = () => ({
        location: { id: 10, name: 'HLF 20', path: 'HLF 20', level: 1 },
        rules,
        out_of_service: rules.some((rule) => rule.missing > 0),
    });
    return {
        posted,
        api: {
            'GET api/v1/stock': (): MockResponse => ({
                status: 200,
                body: {
                    locations: rules.length === 0 ? [] : [entry()],
                    summary: {
                        locations: rules.length === 0 ? 0 : 1,
                        out_of_service: rules.some((rule) => rule.missing > 0) ? 1 : 0,
                    },
                },
            }),
            'GET api/v1/device-types': (): MockResponse => ({
                status: 200,
                body: { device_types: TYPES },
            }),
            'GET api/v1/locations': (): MockResponse => ({
                status: 200,
                body: { locations: LOCATIONS },
            }),
            'POST api/v1/locations/10/stock-rules': (request: MockRequest): MockResponse => {
                posted.push(request.body);
                const sent = (
                    request.body as { rules: { min_count: number; device_type_ids: number[] }[] }
                ).rules;
                rules = sent.map((rule, index) => ({
                    id: 10 + index,
                    min_count: rule.min_count,
                    types: TYPES.filter((type) => rule.device_type_ids.includes(type.id)).map(
                        (type) => ({ id: type.id, name: type.name }),
                    ),
                    have: options.have ?? 5,
                    missing: Math.max(0, rule.min_count - (options.have ?? 5)),
                }));
                return {
                    status: 200,
                    body: {
                        stock:
                            rules.length === 0
                                ? null
                                : { rules, out_of_service: rules.some((r) => r.missing > 0) },
                    },
                };
            },
        } as Record<string, (request: MockRequest) => MockResponse>,
    };
}

let server = stockServer();

function admin(level: 'read' | 'write' = 'write') {
    const areas = {
        ...(ADMIN_RIGHTS['areas'] as Record<string, 'read' | 'write'>),
        locations: level,
    };
    return {
        status: 200,
        body: meBody(
            'admin',
            'Thomas Keller',
            permissionsJson(areas, ADMIN_RIGHTS['actions'] as string[]),
        ),
    } as const;
}

test.describe('Sollbestand mit Recht „Ändern“', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test.beforeEach(async ({ page }) => {
        server = stockServer();
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Liste: Soll, Ist, Fehlendes und „außer Betrieb“', async ({ page }) => {
        await page.goto('/firefighting/test/standorte');
        const panel = page.getByTestId('stock-panel');
        await expect(panel.getByTestId('stock-summary')).toHaveText(
            '1 Standort mit Sollbestand, 1 außer Betrieb',
        );
        const entry = panel.getByTestId('stock-entry');
        await expect(entry).toContainText('HLF 20');
        await expect(entry.getByTestId('stock-chip')).toHaveText('außer Betrieb');
        await expect(entry).toContainText('mindestens 3 Atemschutzmaske: 2 vorhanden, 1 fehlt');
    });

    test('Standortliste: Chip „außer Betrieb“ am Fahrzeug, nach dem Erfüllen weg (T21)', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/standorte');
        const node = page.getByTestId('location-10');
        await expect(node.getByTestId('location-out-of-service').first()).toHaveText(
            'außer Betrieb',
        );
        await expect(
            page.getByTestId('location-11').getByTestId('location-out-of-service'),
        ).toHaveCount(0);
        await page.getByRole('button', { name: 'Sollbestand von HLF 20 bearbeiten' }).click();
        await page.getByTestId('form-dialog').getByLabel('Regel 1: Soll-Anzahl').fill('2');
        await page.getByTestId('form-dialog').getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('stock-success')).toBeVisible();
        await expect(page.getByTestId('location-out-of-service')).toHaveCount(0);
    });

    test('Dashboard: Kachel „Außer Betrieb“ führt zu Standorte (T21)', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        const tile = page.getByTestId('tile-stock');
        await expect(tile).toContainText('Außer Betrieb');
        await expect(tile).toContainText('1');
        await expect(tile).toContainText('von 1 Standort mit Sollbestand');
        await tile.click();
        await expect(page).toHaveURL(/\/firefighting\/test\/standorte$/);
    });

    for (const width of [390, 768, 1024]) {
        test(`Sollbestand (Runde 14): ${String(width)} px alle Bedienelemente erreichbar`, async ({
            page,
        }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/firefighting/test/standorte');
            await expect(page.getByTestId('stock-entry')).toBeVisible();
            expect(await horizontalOverflow(page)).toBeLessThanOrEqual(0);
            expect(await unreachableControls(page, '[data-testid="stock-entry"]')).toEqual([]);
        });
    }

    test('Fenster: Regel ändern, zweite mit zwei Typen, speichern', async ({ page }) => {
        await page.goto('/firefighting/test/standorte');
        await page.getByRole('button', { name: 'Sollbestand von HLF 20 bearbeiten' }).click();
        const dialog = page.getByTestId('form-dialog');
        await expect(dialog.getByTestId('stock-draft')).toHaveCount(1);
        await expect(dialog.getByLabel('Regel 1: Soll-Anzahl')).toHaveValue('3');
        await expect(dialog.getByLabel('Alter Typ')).toHaveCount(0);

        await dialog.getByLabel('Regel 1: Soll-Anzahl').fill('2');
        await dialog.getByTestId('stock-add').click();
        const second = dialog.getByTestId('stock-draft').nth(1);
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog).toContainText(
            'Regel 2: Bitte mindestens einen Ausrüstungstyp wählen.',
        );

        await second.getByLabel('Atemschutzmaske').check();
        await second.getByLabel('Funkgerät').check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();

        await expect(page.getByTestId('stock-success')).toHaveText(
            'Sollbestand von „HLF 20“ gespeichert.',
        );
        expect(server.posted).toEqual([
            {
                rules: [
                    { min_count: 2, device_type_ids: [1] },
                    { min_count: 1, device_type_ids: [1, 2] },
                ],
            },
        ]);
        await expect(page.getByTestId('stock-chip')).toHaveText('vollständig');
        await expect(page.getByTestId('stock-entry')).toContainText(
            'Atemschutzmaske oder Funkgerät',
        );
    });

    test('alle Regeln entfernen', async ({ page }) => {
        await page.goto('/firefighting/test/standorte');
        await page.getByRole('button', { name: 'Sollbestand von HLF 20 bearbeiten' }).click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByRole('button', { name: 'Regel 1 entfernen' }).click();
        await expect(dialog).toContainText('Ohne Regeln hat der Standort keinen Sollbestand.');
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByTestId('stock-success')).toHaveText(
            'Sollbestand von „HLF 20“ entfernt.',
        );
        await expect(page.getByTestId('stock-summary')).toHaveText(
            'Noch kein Sollbestand festgelegt.',
        );
    });

    test('U4: Sprunglink oben, Fach mit Fahrzeug, Satz zu mehreren Typen, Folge der neuen Regel', async ({
        page,
    }) => {
        server = stockServer({ have: 2 });
        await page.setViewportSize({ width: 390, height: 700 });
        await page.goto('/firefighting/test/standorte');
        const jump = page.getByTestId('stock-jump');
        await expect(jump).toHaveText('Sollbestand (1 außer Betrieb) ↓');
        await jump.click();
        await expect(page.getByRole('heading', { name: 'Sollbestand' })).toBeFocused();
        await expect(page.getByRole('heading', { name: 'Sollbestand' })).toBeInViewport();

        await page.getByTestId('stock-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByRole('combobox', { name: 'Standort' }).click();
        await expect(page.getByRole('option', { name: 'HLF 20 / G1' })).toBeVisible();
        // Das Fach nennt sein Fahrzeug; gespeichert wird hier für das Fahrzeug selbst.
        await page.getByRole('option', { name: 'HLF 20', exact: true }).click();
        await expect(dialog.getByTestId('stock-draft')).toHaveCount(1);
        await dialog.getByRole('button', { name: 'Regel 1 entfernen' }).click();
        await dialog.getByTestId('stock-add').click();
        await expect(dialog).toContainText(
            'Mehrere Typen werden zusammen gezählt. Für getrennte Zahlen legen Sie eine zweite Regel an.',
        );
        await dialog.getByLabel('Regel 1: Soll-Anzahl').fill('4');
        await dialog.getByLabel('Atemschutzmaske').check();
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        // Die neue Regel hat Folgen: Das Fach gilt als außer Betrieb, und die Seite sagt es.
        await expect(page.getByTestId('stock-success')).toHaveText(
            'Sollbestand von „HLF 20“ gespeichert.',
        );
        await expect(page.getByTestId('stock-outage')).toContainText(
            'hat zurzeit nur 2 von 4 Atemschutzmaske (einsatzbereit) und gilt deshalb als außer Betrieb.',
        );
    });

    test('Standort wählen und neu festlegen', async ({ page }) => {
        await page.goto('/firefighting/test/standorte');
        await page.getByTestId('stock-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByRole('button', { name: 'Speichern' }).click();
        await expect(dialog).toContainText('Bitte wählen Sie den Standort.');
        await dialog.getByRole('combobox', { name: 'Standort' }).click();
        await page.getByRole('option', { name: 'G1' }).click();
        await expect(dialog.getByTestId('stock-draft')).toHaveCount(0);
    });

    test('Handy-Breite: kein waagerechter Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto('/firefighting/test/standorte');
        await expect(page.getByTestId('stock-entry')).toBeVisible();
        expect(await horizontalOverflow(page)).toBe(0);
    });
});

test.describe('Dashboard ohne Regeln oder ohne Recht auf Standorte (T21)', () => {
    test.describe('Stadt ohne Regeln', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': admin(),
                ...ADMIN_OVERVIEW,
            },
        });

        test('keine Kachel', async ({ page }) => {
            await page.goto('/firefighting/test/dashboard');
            await expect(page.getByTestId('tile-readiness')).toBeVisible();
            await expect(page.getByTestId('tile-stock')).toHaveCount(0);
        });
    });
});

test.describe('Sollbestand nur ansehen', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin('read'),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api),
        },
    });

    test('keine Knöpfe zum Ändern', async ({ page }) => {
        server = stockServer();
        await page.goto('/firefighting/test/standorte');
        await expect(page.getByTestId('stock-entry')).toBeVisible();
        await expect(page.getByTestId('stock-open')).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: /Sollbestand von .* bearbeiten/ }),
        ).toHaveCount(0);
    });
});
