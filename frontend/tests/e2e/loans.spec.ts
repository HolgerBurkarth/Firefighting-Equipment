import { ADMIN_RIGHTS, expect, meBody, permissionsJson, test } from './fixtures';
import type { MockRequest, MockResponse } from './fixtures';
import { ADMIN_OVERVIEW } from './support/admin';
import { deviceJson, devicesEndpoint, fullDevice } from './support/devices';
import { delegate } from './support/masterData';
import { horizontalOverflow } from './support/layout';

/**
 * Seite „Verleih“ (Phase 2, P2.5): offene Verleihe nach Leihnehmer, „Verleihen …“ mit Nummer, Leihnehmer,
 * Ansprechpartner, „Rückgabe bis“; Kachel im Dashboard und Zähler im Menü. Der Bereich kommt nur in
 * Städten mit der Aufgabe „Ausgeliehen an“ oder „Rückgabe“ in die Rechte (Server).
 */

const ACTIONS = ADMIN_RIGHTS['actions'] as string[];

function admin(level: 'read' | 'write' = 'write') {
    const areas = {
        ...(ADMIN_RIGHTS['areas'] as Record<string, 'read' | 'write'>),
        loans: level,
    };
    return {
        status: 200,
        body: meBody('admin', 'Thomas Keller', permissionsJson(areas, ACTIONS)),
    } as const;
}

const NACHBAR = { id: 80, name: 'FF Nachbar' };
const SCHULE = { id: 81, name: 'Schule' };

interface LoanRow {
    id: number;
    device: Record<string, unknown>;
    borrower: { id: number; name: string };
    due: string | null;
    contact: string | null;
}

function loansServer(options: { borrowers?: boolean; empty?: boolean } = {}) {
    const hasBorrowers = options.borrowers ?? true;
    const catalog = [
        deviceJson(1, { identifier: 'M1' }),
        deviceJson(2, { identifier: 'M2' }),
        deviceJson(3, { identifier: 'M3', status: 'retired', status_label: 'ausgesondert' }),
    ];
    let loans: LoanRow[] =
        options.empty === true
            ? []
            : [
                  {
                      id: 1,
                      device: deviceJson(10, {
                          identifier: 'HRT 16',
                          location: { id: 80, name: 'FF Nachbar', path: 'FF Nachbar' },
                      }),
                      borrower: NACHBAR,
                      due: '2026-11-01',
                      contact: 'Herr Maier',
                  },
                  {
                      id: 2,
                      device: deviceJson(11, {
                          identifier: 'HRT 20',
                          location: { id: 80, name: 'FF Nachbar', path: 'FF Nachbar' },
                      }),
                      borrower: NACHBAR,
                      due: null,
                      contact: null,
                  },
              ];
    const posted: unknown[] = [];
    const state = () => ({
        today: '2026-11-09',
        summary: {
            open: loans.length,
            overdue: loans.filter((l) => l.due !== null && l.due < '2026-11-09').length,
        },
        shown: loans.length,
        loans: loans.map((loan) => ({
            id: loan.id,
            device: loan.device,
            borrower: loan.borrower,
            contact: loan.contact,
            due_back_on: loan.due,
            overdue: loan.due !== null && loan.due < '2026-11-09',
            days_overdue: loan.due !== null && loan.due < '2026-11-09' ? 8 : 0,
            comment: null,
            lent_at: '2026-11-01T08:00:00Z',
            lent_by: { id: 1, name: 'Thomas Keller' },
        })),
        borrowers: hasBorrowers ? [NACHBAR, SCHULE] : [],
        returns: [],
        labels: { borrower: 'Ausgeliehen an', returns: 'Rückgabe' },
    });
    return {
        posted,
        api: {
            'GET api/v1/loans': (): MockResponse => ({ status: 200, body: state() }),
            'GET api/v1/devices': devicesEndpoint(catalog, (q) =>
                catalog.filter(
                    (device) => String(device['identifier']).toLowerCase() === q.toLowerCase(),
                ),
            ),
            'GET api/v1/devices/1': (): MockResponse => ({
                status: 200,
                body: { device: fullDevice(catalog[0] ?? {}) },
            }),
            'GET api/v1/devices/2': (): MockResponse => ({
                status: 200,
                body: { device: fullDevice(catalog[1] ?? {}) },
            }),
            'GET api/v1/devices/3': (): MockResponse => ({
                status: 200,
                body: { device: fullDevice(catalog[2] ?? {}) },
            }),
            'POST api/v1/loans': (request: MockRequest): MockResponse => {
                const body = request.body as {
                    devices: { id: number; version: number }[];
                    borrower_location_id: number;
                    due_back_on?: string;
                    contact?: string;
                };
                posted.push(body);
                const borrower = body.borrower_location_id === 80 ? NACHBAR : SCHULE;
                const results = body.devices.map((entry) => {
                    const device = catalog.find((d) => d['id'] === entry.id) ?? {};
                    if (entry.id === 2) {
                        return {
                            id: 2,
                            outcome: 'error',
                            replayed: false,
                            device: fullDevice(device),
                            error: {
                                error: 'rule_violation',
                                message: 'M2 ist schon verliehen (an „Schule“).',
                            },
                        };
                    }
                    const moved = {
                        ...device,
                        version: 2,
                        location: { id: borrower.id, name: borrower.name, path: borrower.name },
                    };
                    loans = [
                        ...loans,
                        {
                            id: 9,
                            device: moved,
                            borrower,
                            due: body.due_back_on ?? null,
                            contact: body.contact ?? null,
                        },
                    ];
                    return {
                        id: entry.id,
                        outcome: 'applied',
                        replayed: false,
                        device: fullDevice(moved),
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
                        borrower,
                    },
                };
            },
        } as Record<string, ((request: MockRequest) => MockResponse) | MockResponse>,
    };
}

let server = loansServer();

test.beforeEach(() => {
    server = loansServer();
});

test.describe('Verleih: erster Verleih', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api as Record<string, (request: MockRequest) => MockResponse>),
        },
    });

    test.beforeEach(async ({ page }) => {
        server = loansServer({ empty: true });
        await page.setViewportSize({ width: 1280, height: 800 });
    });

    test('Meldung „… verliehen.“, nie kurz „nichts verliehen“ darunter', async ({ page }) => {
        await page.goto('/firefighting/test/verleih');
        await expect(page.getByTestId('loan-summary')).toHaveText('Zurzeit ist nichts verliehen.');
        await page.getByTestId('lend-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByTestId('lend-code').fill('M1');
        await dialog.getByTestId('lend-code').press('Enter');
        await dialog.getByRole('combobox', { name: 'Ausgeliehen an' }).click();
        await page.getByRole('option', { name: 'Schule' }).click();
        await dialog.getByRole('button', { name: 'Verleihen', exact: true }).click();

        await expect(page.getByTestId('loan-result')).toHaveText(
            '1 Ausrüstung an „Schule“ verliehen.',
        );
        // Zwischen der Meldung und dem neuen Stand nie der alte, leere Zustand.
        await expect(page.getByText('Zurzeit ist nichts verliehen.')).toHaveCount(0);
        await expect(page.getByTestId('loan-summary')).toHaveText('1 verliehen');
    });
});

test.describe('Verleih mit Recht „Ändern“', () => {
    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => server.api as Record<string, (request: MockRequest) => MockResponse>),
        },
    });

    test.beforeEach(async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
    });

    test('Menü, Zusammenfassung, Gruppe je Leihnehmer, überfällig rot', async ({ page }) => {
        await page.goto('/firefighting/test/verleih');
        await expect(
            page
                .getByRole('navigation', { name: 'Hauptmenü' })
                .getByRole('link', { name: /Verleih/ }),
        ).toBeVisible();
        await expect(page.getByRole('heading', { level: 1, name: 'Verleih' })).toBeVisible();
        await expect(page.getByTestId('loan-summary')).toHaveText(
            '2 verliehen, davon 1 überfällig',
        );
        await expect(page.getByTestId('loan-group')).toHaveCount(1);
        const rows = page.getByTestId('loan-row');
        await expect(rows).toHaveCount(2);
        await expect(rows.first()).toContainText('HRT 16');
        await expect(rows.first().getByTestId('loan-due')).toHaveText(
            'seit 8 Tagen überfällig (bis 01.11.2026)',
        );
        await expect(rows.first()).toContainText('Herr Maier');
        await expect(rows.nth(1).getByTestId('loan-due')).toHaveText('keine Frist');
    });

    test('Verleihen: Nummer hinzufügen, unbekannt und ausgesondert melden, Leihnehmer, senden', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/verleih');
        await page.getByTestId('lend-open').click();
        const dialog = page.getByTestId('form-dialog');
        await dialog.getByTestId('lend-code').fill('X99');
        await dialog.getByTestId('lend-code').press('Enter');
        await expect(dialog).toContainText('Keine Ausrüstung mit diesem Code gefunden.');
        await dialog.getByTestId('lend-code').fill('M3');
        await dialog.getByTestId('lend-code').press('Enter');
        await expect(dialog).toContainText('M3 ist ausgesondert');

        await dialog.getByTestId('lend-code').fill('M1');
        await dialog.getByTestId('lend-code').press('Enter');
        await expect(dialog.getByTestId('lend-list')).toContainText('M1');
        await dialog.getByTestId('lend-code').fill('M1');
        await dialog.getByTestId('lend-code').press('Enter');
        await expect(dialog).toContainText('M1 steht schon in der Liste.');
        await dialog.getByTestId('lend-code').fill('M2');
        await dialog.getByTestId('lend-add').click();
        await expect(dialog.getByTestId('lend-list').getByRole('listitem')).toHaveCount(2);

        await dialog.getByRole('button', { name: 'Verleihen', exact: true }).click();
        await expect(dialog).toContainText('Bitte wählen Sie den Leihnehmer.');
        await dialog.getByRole('combobox', { name: 'Ausgeliehen an' }).click();
        await page.getByRole('option', { name: 'Schule' }).click();
        await dialog.getByLabel('Ansprechpartner (optional)').fill('Frau Weber');
        await dialog.getByLabel('Rückgabe bis (optional)').fill('2026-12-01');
        await dialog.getByRole('button', { name: 'Verleihen', exact: true }).click();

        await expect(page.getByTestId('loan-result')).toHaveText(
            'Verleihen: 1 Ausrüstung geändert, 1 nicht möglich an „Schule“.',
        );
        await expect(page.getByTestId('loan-failures')).toContainText('M2 ist schon verliehen');
        expect(server.posted).toEqual([
            {
                devices: [
                    { id: 1, version: 1 },
                    { id: 2, version: 1 },
                ],
                borrower_location_id: 81,
                contact: 'Frau Weber',
                due_back_on: '2026-12-01',
            },
        ]);
        await expect(page.getByTestId('loan-group')).toHaveCount(2);
    });

    test('Dashboard: Kachel „Verliehen“ führt zur Seite', async ({ page }) => {
        await page.goto('/firefighting/test/dashboard');
        const tile = page.getByTestId('tile-loans');
        await expect(tile).toContainText('Verliehen');
        await expect(tile).toContainText('2');
        await expect(tile).toContainText('davon 1 überfällig');
        await tile.click();
        await expect(page).toHaveURL(/\/firefighting\/test\/verleih$/);
    });

    test('Handy-Breite: kein waagerechter Überstand', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await page.goto('/firefighting/test/verleih');
        await expect(page.getByTestId('loan-row')).toHaveCount(2);
        expect(await horizontalOverflow(page)).toBe(0);
    });
});

test.describe('Ohne Leihnehmer und nur ansehen', () => {
    test.describe('Stadt ohne Standort „Ausgeliehen an“', () => {
        const empty = loansServer({ borrowers: false });
        test.use({
            api: {
                'GET api/v1/auth/me': admin(),
                ...ADMIN_OVERVIEW,
                ...delegate(
                    () => empty.api as Record<string, (request: MockRequest) => MockResponse>,
                ),
            },
        });

        test('Hinweis, Knopf gesperrt', async ({ page }) => {
            await page.goto('/firefighting/test/verleih');
            await expect(page.getByTestId('no-borrower')).toBeVisible();
            await expect(page.getByTestId('lend-open')).toBeDisabled();
        });
    });

    test.describe('Recht nur „Ansehen“', () => {
        test.use({
            api: {
                'GET api/v1/auth/me': admin('read'),
                ...ADMIN_OVERVIEW,
                ...delegate(
                    () => server.api as Record<string, (request: MockRequest) => MockResponse>,
                ),
            },
        });

        test('kein Knopf „Verleihen“', async ({ page }) => {
            await page.goto('/firefighting/test/verleih');
            await expect(page.getByTestId('loan-row')).toHaveCount(2);
            await expect(page.getByTestId('lend-open')).toHaveCount(0);
        });
    });
});

/** Zustandsbehafteter Server der Rückgabe: Aushang erzeugen/widerrufen, „Rückgaben bearbeiten“. */
function returnsServer() {
    const key = 'K'.repeat(43);
    let linkCreatedAt: string | null = null;
    let waiting = [
        deviceJson(21, {
            identifier: 'HRT 16',
            status: 'defective',
            status_label: 'defekt',
            location: { id: 90, name: 'Rückgabe', path: 'Rückgabe' },
        }),
        deviceJson(22, {
            identifier: 'HRT 20',
            location: { id: 90, name: 'Rückgabe', path: 'Rückgabe' },
        }),
        deviceJson(23, {
            identifier: 'HRT 3',
            status: 'used',
            status_label: 'benutzt',
            location: { id: 90, name: 'Rückgabe', path: 'Rückgabe' },
        }),
    ];
    const posted: { path: string; body: unknown }[] = [];
    const process =
        (path: string) =>
        (request: MockRequest): MockResponse => {
            posted.push({ path, body: request.body });
            const entries = (request.body as { devices: { id: number }[] }).devices;
            const results = entries.map((entry) => {
                const device = waiting.find((d) => d['id'] === entry.id) ?? {};
                waiting = waiting.filter((d) => d['id'] !== entry.id);
                return {
                    id: entry.id,
                    outcome: 'applied',
                    replayed: false,
                    device: fullDevice(device),
                    error: null,
                };
            });
            return {
                status: 200,
                body: {
                    results,
                    summary: {
                        total: results.length,
                        applied: results.length,
                        unchanged: 0,
                        errors: 0,
                    },
                    target: { id: 1, name: 'Ziel' },
                },
            };
        };
    return {
        key,
        posted,
        api: {
            'GET api/v1/loans': (): MockResponse => ({
                status: 200,
                body: {
                    today: '2026-11-09',
                    summary: { open: 0, overdue: 0 },
                    shown: 0,
                    loans: [],
                    borrowers: [NACHBAR],
                    returns: [{ id: 90, name: 'Rückgabe', link_created_at: linkCreatedAt }],
                    in_returns: waiting,
                    labels: { borrower: 'Ausgeliehen an', returns: 'Rückgabe' },
                },
            }),
            'POST api/v1/return-points/90/renew': (): MockResponse => {
                linkCreatedAt = '2026-11-09T09:00:00Z';
                return { status: 200, body: { key } };
            },
            'POST api/v1/return-points/90/revoke': (): MockResponse => {
                linkCreatedAt = null;
                return { status: 200, body: { revoked: true } };
            },
            'POST api/v1/loans/to-store': process('to-store'),
            'POST api/v1/loans/to-collection': process('to-collection'),
        } as Record<string, (request: MockRequest) => MockResponse>,
    };
}

test.describe('Rückgabe: Aushang und Rückgaben bearbeiten', () => {
    let returns = returnsServer();

    test.use({
        api: {
            'GET api/v1/auth/me': admin(),
            ...ADMIN_OVERVIEW,
            ...delegate(() => returns.api),
        },
    });

    test.beforeEach(async ({ page }) => {
        returns = returnsServer();
        await page.setViewportSize({ width: 1280, height: 900 });
    });

    test('Aushang erzeugen: Link mit Schlüssel im Fragment, danach erneuern und widerrufen', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/verleih');
        const place = page.getByTestId('return-place');
        await expect(place).toContainText('Rückgabe');
        await expect(page.getByTestId('return-link-state')).toHaveText('Kein gültiger Aushang');

        await place.getByTestId('renew-key').click();
        const issued = page.getByTestId('issued-key');
        await expect(issued).toBeVisible();
        await expect(issued.getByTestId('issued-link')).toHaveText(
            new RegExp(`/firefighting/test/rueckgabe#schluessel=${returns.key}$`),
        );
        await expect(issued.getByRole('img', { name: /QR-Code/ })).toBeVisible();
        await expect(page.getByTestId('return-poster')).toHaveCount(1);
        await expect(page.getByTestId('return-link-state')).toContainText('Aushang gültig seit');

        await issued.getByRole('button', { name: 'Schließen' }).click();
        await expect(page.getByTestId('issued-key')).toHaveCount(0);
        await expect(page.getByTestId('return-poster')).toHaveCount(0);

        await place.getByTestId('renew-key').click();
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            'Der bisherige Aushang gilt danach nicht mehr.',
        );
        await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Abbrechen' }).click();
        await place.getByTestId('revoke-key').click();
        await page
            .getByTestId('confirm-dialog')
            .getByRole('button', { name: 'Ungültig machen' })
            .click();
        await expect(page.getByTestId('return-link-state')).toHaveText('Kein gültiger Aushang');
    });

    test('In der Rückgabe: Benutzte und Defekte in die Sammelstelle, der Rest ins Lager', async ({
        page,
    }) => {
        await page.goto('/firefighting/test/verleih');
        const list = page.getByTestId('in-returns');
        await expect(list.getByTestId('return-device')).toHaveCount(3);
        await expect(list.getByTestId('to-store')).toBeDisabled();

        await list.getByLabel('HRT 16 auswählen').check();
        await list.getByLabel('HRT 3 auswählen').check();
        await list.getByTestId('to-collection').click();
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            '2 Ausrüstungen in die Sammelstelle bringen?',
        );
        await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Bringen' }).click();
        await expect(page.getByTestId('loan-result')).toHaveText(
            'In die Sammelstelle: 2 Ausrüstungen geändert.',
        );
        await expect(list.getByTestId('return-device')).toHaveCount(1);

        await list.getByLabel('HRT 20 auswählen').check();
        await list.getByTestId('to-store').click();
        await expect(page.getByTestId('confirm-dialog')).toContainText(
            '1 Ausrüstung ins Lager bringen?',
        );
        await expect(page.getByTestId('confirm-dialog')).toContainText('Sie steht dann');
        await page.getByTestId('confirm-dialog').getByRole('button', { name: 'Bringen' }).click();
        await expect(page.getByTestId('loan-result')).toHaveText(
            'Ins Lager: 1 Ausrüstung geändert.',
        );
        await expect(list).toContainText('Zurzeit steht nichts in der Rückgabe.');
        expect(returns.posted.map((entry) => entry.path)).toEqual(['to-collection', 'to-store']);
    });
});
