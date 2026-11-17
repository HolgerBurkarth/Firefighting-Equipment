import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import type { Locator, Page } from '@playwright/test';
import {
    ADMIN_RIGHTS,
    DEFAULT_CLIENT_CONFIG,
    expect,
    HTTP_ERROR_CONSOLE,
    meBody,
    NOT_AUTHENTICATED,
    permissionsJson,
    test,
    TEST_TENANT,
    type MockRequest,
    type MockResponse,
} from '../e2e/fixtures';
import {
    adminOverview,
    assignableRolesJson,
    noticeJson,
    summaryJson,
    tenantEventJson,
    userJson,
} from '../e2e/support/admin';
import {
    deviceDetail,
    deviceJson,
    devicesEndpoint,
    fullDevice,
    LOCATIONS,
    locationJson,
} from '../e2e/support/devices';
import { deviceTypeJson } from '../e2e/support/masterData';
import { CURRENT_SESSION_ONLY } from '../e2e/support/profile';
import { DESKTOP, PHONE, renderGuide, shoot, type Step } from './guide';

/**
 * Anleitungen je Rolle (Runde 14, Punkt 4). Die App läuft wie in den Browser-Tests gegen den Build, die
 * API ist eine Attrappe mit erfundenen Beispieldaten (Feuerwehr Musterstadt, Anna Beispiel, M12 …),
 * nie echte Daten. Erzeugt `docs/anleitungen/*.pdf`; Aufruf `npm run docs:anleitungen` (nach
 * `npm run build`). Rechte der Rollen Gerätewart und Geräteträger nach
 * docs/MERKBLATT-EINRICHTUNG-PHASE-2.md, Abschnitt 6.
 */

/** Standard-Symbol der App (Flamme) statt des Platzhalter-Bildes der Browser-Tests. */
const APP_ICON = readFileSync(
    fileURLToPath(new URL('../../../resources/app-icon/app-icon-192.png', import.meta.url)),
);

const TODAY = new Date('2026-11-12T08:30:00+01:00');
const TENANT = { ...TEST_TENANT, body: { ...TEST_TENANT.body, name: 'Feuerwehr Musterstadt' } };
const AREAS = ADMIN_RIGHTS['areas'] as Record<string, 'read' | 'write'>;
const ALL_ACTIONS = ADMIN_RIGHTS['actions'] as string[];

type Role = 'user' | 'carrier' | 'keeper' | 'admin';

const RIGHTS: Record<Role, Record<string, unknown>> = {
    user: permissionsJson({ search: 'read', due: 'read' }, ['book.move', 'book.status']),
    carrier: permissionsJson(
        { search: 'read', due: 'read' },
        ['book.move', 'book.status', 'device.used', 'device.swap'],
        false,
        ['single_use_used_only'],
    ),
    keeper: permissionsJson(
        {
            dashboard: 'read',
            search: 'read',
            devices: 'write',
            locations: 'write',
            device_types: 'write',
            due: 'write',
            log: 'write',
            maintenance: 'write',
            loans: 'write',
        },
        [
            'book.move',
            'book.status',
            'device.used',
            'device.swap',
            'device.create',
            'device.edit',
            'device.code',
            'devices.bulk',
            'csv.import',
            'csv.export',
            'mail.log',
        ],
    ),
    admin: permissionsJson({ ...AREAS, maintenance: 'write', loans: 'write' }, ALL_ACTIONS),
};

const ROLE_NAMES: Record<Role, string> = {
    user: 'Benutzer',
    carrier: 'Geräteträger',
    keeper: 'Gerätewart',
    admin: 'Admin',
};

function me(role: Role): MockResponse {
    const body = meBody(role === 'admin' ? 'admin' : 'user', 'Anna Beispiel', RIGHTS[role]);
    return {
        status: 200,
        body: {
            ...body,
            user: { ...(body['user'] as Record<string, unknown>), role_name: ROLE_NAMES[role] },
            tenant: { slug: 'test', name: 'Feuerwehr Musterstadt' },
        },
    };
}

// --- erfundene Beispieldaten ---------------------------------------------------------------------

const DEVICES = [
    deviceJson(12, {
        identifier: 'M12',
        next_inspection_due: '2026-11-20',
        location: { id: 11, name: 'G1', path: 'HLF 20 / G1' },
    }),
    deviceJson(7, {
        identifier: 'M7',
        next_inspection_due: '2026-11-03',
        location: { id: 12, name: 'G2', path: 'HLF 20 / G2' },
    }),
    deviceJson(13, {
        identifier: 'M13',
        location: { id: 30, name: 'Lager', path: 'Lager' },
    }),
    deviceJson(15, {
        identifier: 'PA 3',
        device_type: { id: 2, name: 'Atemschutzgerät' },
        next_inspection_due: '2027-02-10',
        location: { id: 20, name: 'LF 10', path: 'LF 10' },
    }),
    deviceJson(16, {
        identifier: 'F5',
        device_type: { id: 3, name: 'Funkgerät' },
        status: 'maintenance',
        status_label: 'in Wartung',
        location: { id: 30, name: 'Lager', path: 'Lager' },
    }),
    deviceJson(17, {
        identifier: 'M9',
        status: 'defective',
        status_label: 'defekt',
        location: { id: 30, name: 'Lager', path: 'Lager' },
    }),
];
const SELECTED = DEVICES[0] ?? {};

/** Ausrüstung im Raum „Pool“ (nur Admin-Anleitung: viele auf einmal umbuchen). */
const POOL = { id: 40, name: 'Pool', path: 'Pool' };
const POOL_DEVICES = [20, 21, 22, 23].map((n) =>
    deviceJson(30 + n, {
        identifier: `M${String(n)}`,
        location: POOL,
        next_inspection_due: '2027-05-15',
    }),
);

const STOCK_ENTRY = {
    location: { id: 10, name: 'HLF 20', path: 'HLF 20', level: 1 },
    rules: [
        {
            id: 1,
            min_count: 3,
            types: [{ id: 1, name: 'Atemschutzmaske' }],
            have: 2,
            missing: 1,
        },
    ],
    out_of_service: true,
};

const BORROWER = { id: 80, name: 'FF Nachbarort' };
const LOAN_DEVICE = deviceJson(21, {
    identifier: 'HRT 16',
    device_type: { id: 4, name: 'Handfunkgerät' },
    location: { id: 80, name: 'FF Nachbarort', path: 'FF Nachbarort' },
});
const RETURNED = deviceJson(22, {
    identifier: 'HRT 20',
    device_type: { id: 4, name: 'Handfunkgerät' },
    status: 'used',
    status_label: 'benutzt',
    location: { id: 90, name: 'Rückgabe', path: 'Rückgabe' },
});

function stage(label: string, list: Record<string, unknown>[]): Record<string, unknown> {
    return {
        label,
        locations: [{ id: 50, name: label }],
        total: list.length,
        shown: list.length,
        devices: list,
    };
}

const USERS = [
    userJson(7, {
        label: 'Anna Beispiel',
        name: 'Anna Beispiel',
        display_name: 'Anna Beispiel',
        email: 'anna.beispiel@example.org',
        status: 'active',
        status_label: 'aktiv',
        role: 'admin',
        role_label: 'Admin',
        role_id: 1,
        role_name: 'Admin',
    }),
    userJson(8, {
        label: 'Max Muster',
        name: 'Max Muster',
        display_name: 'Max Muster',
        email: 'max.muster@example.org',
        status: 'active',
        status_label: 'aktiv',
        role_id: 3,
        role_name: 'Gerätewart',
        claude_access: 'read',
        claude_access_label: 'Lesen',
        claude_connections: { count: 1, last_used_at: '2026-11-12T07:00:00Z' },
    }),
    userJson(21, {
        label: 'Erika Probe',
        name: 'Erika Probe',
        email: 'erika.probe@example.org',
        invitation_code: { id: 4, label: 'Dienstabend November' },
    }),
];

const CODE = {
    id: 4,
    label: 'Dienstabend November',
    status: 'active',
    status_label: 'aktiv',
    use_count: 3,
    max_uses: 50,
    expires_at: '2026-12-12T13:05:00Z',
    created_at: '2026-11-12T07:05:00Z',
    revoked_at: null,
    created_by: { id: 7, label: 'Anna Beispiel' },
    role: { id: 2, name: 'Benutzer' },
};

const IMPORT_PREVIEW = {
    token: 'c'.repeat(64),
    encoding: 'utf-8',
    delimiter: ';',
    delimiter_label: 'Semikolon',
    columns: ['Nummer', 'Scan-Code', 'Ausrüstungstyp', 'Standort', 'Status', 'Nächste Wartung'],
    summary: { rows: 3, new: 3, changed: 0, unchanged: 0, skipped: 0, errors: 0, warnings: 1 },
    unknown: { device_types: [], locations: [] },
    suggestions: { device_types: [], locations: [] },
    rows: ['M30', 'M31', 'M32'].map((identifier, index) => ({
        line: index + 2,
        identifier,
        result: 'new',
        result_label: 'Neu',
        device: null,
        changes: [
            { field: 'device_type', label: 'Ausrüstungstyp', old: null, new: 'Atemschutzmaske' },
            { field: 'location', label: 'Standort', old: null, new: 'Pool' },
        ],
        messages: [],
        warnings:
            index === 2
                ? [
                      'Der Scan-Code „4006381333931“ entspricht ohne führende Nullen einem vorhandenen Scan-Code („04006381333931“). Hat Excel die Nullen entfernt?',
                  ]
                : [],
    })),
};

let loggedIn = true;

function api(role: Role): Record<string, MockResponse | ((request: MockRequest) => MockResponse)> {
    const catalog = devicesEndpoint(DEVICES, (q) =>
        DEVICES.filter((d) => String(d['identifier']).toLowerCase().includes(q.toLowerCase())),
    );
    return {
        'GET api/v1/auth/me': () => (loggedIn ? me(role) : NOT_AUTHENTICATED),
        ...adminOverview({
            summary: summaryJson({
                ready: 41,
                maintenance: 1,
                defective: 2,
                used: 1,
                overdue: 2,
                dueSoon: 4,
                openNotices: 1,
            }),
            pending: [USERS[2] ?? {}],
            notReady: [DEVICES[4] ?? {}, DEVICES[5] ?? {}],
            events: [
                tenantEventJson(1, {
                    created_at: '2026-11-12T07:12:00Z',
                    occurred_at: '2026-11-12T07:12:00Z',
                    received_at: '2026-11-12T07:12:00Z',
                    user: { id: 8, name: 'Max Muster' },
                }),
                tenantEventJson(2, {
                    created_at: '2026-11-11T18:40:00Z',
                    occurred_at: '2026-11-11T18:40:00Z',
                    received_at: '2026-11-11T18:40:00Z',
                    type_label: 'Status geändert',
                    user: { id: 9, name: 'Lena Probe' },
                    location: { from: null, to: null },
                    status: {
                        from: 'ready',
                        from_label: 'einsatzbereit',
                        to: 'maintenance',
                        to_label: 'in Wartung',
                    },
                    device: {
                        id: 16,
                        identifier: 'F5',
                        device_type: { id: 3, name: 'Funkgerät' },
                    },
                }),
            ],
            devices: DEVICES,
        }),
        'POST api/v1/auth/register': {
            status: 202,
            body: {
                message:
                    'Vielen Dank. Wir haben Ihnen eine E-Mail geschickt; bitte bestätigen Sie darin Ihre Adresse.',
            },
        },
        'GET api/v1/devices': (request) => {
            const params = new URL(request.url).searchParams;
            if (params.get('location_id') === String(POOL.id)) {
                return {
                    status: 200,
                    body: {
                        devices: POOL_DEVICES,
                        total: POOL_DEVICES.length,
                        limit: 50,
                        offset: 0,
                    },
                };
            }
            if (params.get('status') !== null) {
                const list = [DEVICES[4] ?? {}, DEVICES[5] ?? {}];
                return {
                    status: 200,
                    body: { devices: list, total: list.length, limit: 10, offset: 0 },
                };
            }
            return catalog(request);
        },
        ...(role === 'admin'
            ? {
                  'GET api/v1/locations': {
                      status: 200,
                      body: {
                          locations: [
                              ...LOCATIONS['GET api/v1/locations'].body.locations,
                              locationJson(POOL.id, POOL.name, [], 'room', POOL_DEVICES.length),
                          ],
                      },
                  },
              }
            : LOCATIONS),
        ...CURRENT_SESSION_ONLY,
        ...deviceDetail(SELECTED),
        'GET api/v1/client-config': {
            status: 200,
            body: { ...DEFAULT_CLIENT_CONFIG, used_target: { id: 50, name: 'Sammelstelle' } },
        },
        'GET api/v1/maintenance': {
            status: 200,
            body: {
                collection: stage('Sammelstelle', [
                    deviceJson(17, {
                        identifier: 'M9',
                        status: 'defective',
                        status_label: 'defekt',
                        location: { id: 50, name: 'Sammelstelle', path: 'Sammelstelle' },
                        state_changed_at: '2026-11-09T08:00:00Z',
                    }),
                    deviceJson(18, {
                        identifier: 'M2',
                        status: 'used',
                        status_label: 'benutzt',
                        location: { id: 50, name: 'Sammelstelle', path: 'Sammelstelle' },
                        state_changed_at: '2026-11-12T03:00:00Z',
                    }),
                ]),
                workshop: stage('Werkstatt', [
                    deviceJson(19, {
                        identifier: 'M4',
                        status: 'maintenance',
                        status_label: 'in Wartung',
                        location: { id: 60, name: 'Werkstatt', path: 'Werkstatt' },
                        state_changed_at: '2026-11-10T08:00:00Z',
                    }),
                ]),
                default_store: { id: 70, name: 'Hauptlager' },
            },
        },
        'GET api/v1/loans': {
            status: 200,
            body: {
                today: '2026-11-12',
                summary: { open: 1, overdue: 1 },
                shown: 1,
                loans: [
                    {
                        id: 1,
                        device: LOAN_DEVICE,
                        borrower: BORROWER,
                        contact: 'Herr Maier',
                        due_back_on: '2026-11-05',
                        overdue: true,
                        days_overdue: 7,
                        comment: null,
                        lent_at: '2026-10-29T08:00:00Z',
                        lent_by: { id: 8, name: 'Max Muster' },
                    },
                ],
                borrowers: [BORROWER],
                returns: [{ id: 90, name: 'Rückgabe', link_created_at: '2026-11-01T09:00:00Z' }],
                in_returns: [RETURNED],
                labels: { borrower: 'Ausgeliehen an', returns: 'Rückgabe' },
            },
        },
        'GET api/v1/devices/22': { status: 200, body: { device: fullDevice(RETURNED) } },
        'POST api/v1/return-points/90/renew': { status: 200, body: { key: 'K'.repeat(43) } },
        'GET api/v1/stock': {
            status: 200,
            body: { locations: [STOCK_ENTRY], summary: { locations: 1, out_of_service: 1 } },
        },
        'GET api/v1/device-types': {
            status: 200,
            body: {
                device_types: [
                    deviceTypeJson(1, 'Atemschutzmaske', 12),
                    deviceTypeJson(2, 'Atemschutzgerät', 6),
                    deviceTypeJson(3, 'Funkgerät', 9),
                ],
            },
        },
        'GET api/v1/notices': {
            status: 200,
            body: { notices: [noticeJson(3)], total: 1, open: 1, limit: 50, offset: 0 },
        },
        'GET api/v1/users': (request) => {
            const status = new URL(request.url).searchParams.get('status');
            return {
                status: 200,
                body: { users: status === 'pending' ? [USERS[2] ?? {}] : USERS },
            };
        },
        'GET api/v1/invitation-codes': { status: 200, body: { invitation_codes: [CODE] } },
        'GET api/v1/roles': () => {
            const roles = assignableRolesJson();
            const list = roles['roles'] as Record<string, unknown>[];
            return {
                status: 200,
                body: { ...roles, roles: list.filter((r) => Number(r['id']) <= 3) },
            };
        },
        'POST api/v1/auth/invitation/accept': {
            status: 200,
            body: {
                message:
                    'Ihr Konto ist eingerichtet. Bitte melden Sie sich mit Ihrer E-Mail-Adresse und dem neuen Passwort an.',
            },
        },
        'POST api/v1/devices/import/preview': { status: 200, body: IMPORT_PREVIEW },
        'POST api/v1/invitation-codes': {
            status: 201,
            body: { invitation_code: { ...CODE, id: 5, label: null }, code: 'ABCD-EFGH-JKMN' },
        },
        'GET api/v1/auth/notifications': {
            status: 200,
            body: {
                notifications: { device_log: false },
                topics: { selected: null, available: [] },
            },
        },
        'GET api/v1/location-tasks': {
            status: 200,
            body: {
                tasks: ['store', 'collection', 'workshop', 'returns', 'borrower'].map((task) => ({
                    task,
                    label: task,
                    default_label: task,
                    enabled: true,
                    locations: 1,
                })),
            },
        },
    };
}

test.use({ tenant: TENANT, allowedConsoleError: HTTP_ERROR_CONSOLE });

// --- Schritte ------------------------------------------------------------------------------------

type Build = (page: Page) => Promise<Step>;

async function phone(page: Page): Promise<void> {
    await page.setViewportSize(PHONE);
}

async function desktop(page: Page): Promise<void> {
    await page.setViewportSize(DESKTOP);
}

const login: Build = async (page) => {
    await phone(page);
    loggedIn = false;
    await page.goto('/firefighting/test/anmelden');
    await expect(page.getByTestId('login-submit')).toBeVisible();
    await page.getByLabel('E-Mail-Adresse').fill('anna.beispiel@example.org');
    await page.getByLabel('Passwort', { exact: true }).fill('geheim-geheim');
    const shot = await shoot(page, [
        { target: page.getByLabel('E-Mail-Adresse'), label: 'E-Mail-Adresse' },
        { target: page.getByLabel('Passwort', { exact: true }), label: 'Passwort' },
        { target: page.getByTestId('login-submit'), label: 'Anmelden' },
    ]);
    loggedIn = true;
    return {
        title: 'App aufs Handy holen und anmelden',
        text: 'Die Adresse bekommen Sie von Ihrer Feuerwehr, z. B. über den Aushang. Öffnen Sie sie im Browser. iPhone: Safari → Teilen-Symbol → „Zum Home-Bildschirm“. Android: Chrome → Menü ⋮ → „Zum Startbildschirm hinzufügen“. So starten Sie die App künftig wie jede andere. Melden Sie sich mit E-Mail-Adresse und Passwort an.',
        shot,
    };
};

const account: Build = async (page) => {
    await phone(page);
    loggedIn = false;
    await page.goto('/firefighting/test/registrieren#code=ABCD-EFGH-JKMN');
    await expect(page.getByLabel('Einladungscode')).toBeVisible();
    await page.getByLabel('Name', { exact: true }).fill('Anna Beispiel');
    await page.getByLabel('E-Mail-Adresse').fill('anna.beispiel@example.org');
    await page.getByLabel('Passwort', { exact: true }).fill('Kxq7-Lmw2!-lang');
    const form = await shoot(page, [
        {
            target: page.getByLabel('Einladungscode'),
            label: 'Einladungscode (steht schon da, wenn Sie den QR-Code scannen)',
        },
        { target: page.getByRole('button', { name: 'Registrieren' }), label: 'Registrieren' },
    ]);
    await page.getByRole('button', { name: 'Registrieren' }).click();
    await expect(page.getByTestId('register-sent')).toBeVisible();
    const sent = await shoot(page, [
        { target: page.getByTestId('register-sent'), label: 'Bestätigungsmail kommt' },
    ]);
    loggedIn = true;
    return {
        title: 'Konto anlegen',
        text: 'Scannen Sie den QR-Code auf dem Aushang Ihrer Feuerwehr (oder öffnen Sie den Link) und füllen Sie das Formular aus. Danach öffnen Sie die Bestätigungsmail und tippen auf den Link darin; kommt keine Mail, sehen Sie im Spam-Ordner nach. Zuletzt schaltet die Feuerwehr Ihr Konto frei; auch darüber bekommen Sie eine Mail. Erst dann können Sie sich anmelden.',
        shot: form,
        shot2: sent,
    };
};

const search: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/suchen');
    await expect(page.getByTestId('scan-button')).toBeVisible();
    await page.getByPlaceholder('Ausrüstung suchen, z. B. M1').fill('M1');
    await expect(page.getByTestId('search-count')).toBeVisible();
    return {
        title: 'Ausrüstung suchen oder scannen',
        text: 'Tippen Sie die Nummer oder einen Teil davon ein. Oder tippen Sie auf „Code scannen“ und halten Sie die Kamera auf den Code an der Ausrüstung. Ein Tipp auf die Ausrüstung öffnet sie.',
        shot: await shoot(page, [
            {
                target: page.getByPlaceholder('Ausrüstung suchen, z. B. M1'),
                label: 'Nummer eingeben',
            },
            { target: page.getByTestId('scan-button'), label: 'Code scannen' },
        ]),
    };
};

const rebook: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/geraete/12');
    await expect(page.getByRole('button', { name: 'Speichern' })).toBeVisible();
    await page.getByTestId('status-choice').nth(1).click();
    return {
        title: 'Umbuchen: neuer Standort oder Status',
        text: 'Wählen Sie, wohin die Ausrüstung gekommen ist, oder setzen Sie den Status (einsatzbereit, in Wartung, defekt). Bei „defekt“ schreiben Sie kurz, was kaputt ist. Dann „Speichern“.',
        shot: await shoot(page, [
            { target: page.getByTestId('location-choice').first(), label: 'Standort wählen' },
            { target: page.getByTestId('status-choice').first(), label: 'Status wählen' },
            { target: page.getByRole('button', { name: 'Speichern' }), label: 'Speichern' },
        ]),
    };
};

function usedStep(after: string): Build {
    return async (page) => {
        await phone(page);
        await page.goto('/firefighting/test/geraete/12');
        await page.getByTestId('report-used').click();
        const dialog = page.getByRole('alertdialog');
        await expect(dialog).toBeVisible();
        return {
            title: 'Benutzt melden',
            text: `Wurde die Ausrüstung im Einsatz oder bei der Übung benutzt, tippen Sie auf „Benutzt melden“ und bestätigen Sie. Sie kommt in die Sammelstelle; ${after}`,
            shot: await shoot(page, [
                {
                    target: dialog.getByRole('button', { name: 'Benutzt melden' }),
                    label: 'Bestätigen',
                },
            ]),
        };
    };
}

const used = usedStep('dort kümmert sich der Gerätewart darum.');
const usedKeeper = usedStep('Sie finden sie danach unter „Wartung“.');

const swap: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/geraete/12');
    await page.getByTestId('swap-open').click();
    await expect(page.getByTestId('swap-candidates')).toBeVisible();
    await page.getByTestId('swap-state-used').click();
    return {
        title: 'Tauschen',
        text: 'Ist eine Ausrüstung kaputt oder aufgebraucht, tauschen Sie sie: Tippen Sie auf „Tauschen …“, sagen Sie, in welchem Zustand sie ist (benutzt, defekt oder in Wartung), und wählen Sie den Ersatz aus dem Lager. Beide werden in einem Zug umgebucht; die alte kommt in die Sammelstelle.',
        shot: await shoot(page, [
            { target: page.getByTestId('swap-state-used'), label: 'Zustand wählen' },
            { target: page.getByTestId('swap-search'), label: 'Ersatz suchen' },
            { target: page.getByTestId('swap-candidates'), label: 'Ersatz auswählen' },
        ]),
    };
};

const offline: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/suchen');
    await expect(page.getByTestId('scan-button')).toBeVisible();
    await page.context().setOffline(true);
    await page.evaluate(() => window.dispatchEvent(new Event('offline')));
    await expect(page.getByTestId('offline-notice')).toBeVisible();
    const shot = await shoot(page, [
        { target: page.getByTestId('offline-notice'), label: 'Hinweis „Offline“' },
    ]);
    await page.context().setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));
    return {
        title: 'Ohne Netz arbeiten',
        text: 'Im Keller oder im Funkloch funktioniert die App weiter: Sie sehen den zuletzt geladenen Stand und können umbuchen. Die Buchungen werden gesendet, sobald wieder Netz da ist. Melden Sie sich nicht ab, solange Buchungen warten (Uhr-Symbol auf „Suchen“).',
        shot,
    };
};

const terms: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/prueffristen');
    await expect(page.getByTestId('overdue-list')).toBeVisible();
    return {
        title: 'Termine ansehen',
        text: 'Unter „Termine“ sehen Sie, welche Ausrüstung überfällig oder bald zur Wartung fällig ist. Antippen öffnet die Ausrüstung.',
        shot: await shoot(page, [
            { target: page.getByTestId('overdue-list'), label: 'Überfällig (rot)' },
            { target: page.getByTestId('due-soon-list'), label: 'Bald fällig (gelb)' },
        ]),
    };
};

const profile: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/profil');
    await expect(page.getByRole('button', { name: 'Abmelden' }).first()).toBeVisible();
    return {
        title: 'Profil, Passwort und Abmelden',
        text: 'Im Profil ändern Sie Ihr Passwort und den Anzeigemodus (hell oder dunkel). Mit „Abmelden“ melden Sie sich auf diesem Handy ab.',
        shot: await shoot(page, [
            { target: page.getByRole('button', { name: 'Abmelden' }).first(), label: 'Abmelden' },
        ]),
    };
};

const dashboard: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/dashboard');
    await expect(page.getByTestId('tile-readiness')).toBeVisible();
    return {
        title: 'Dashboard: der Überblick',
        text: 'Die Startseite zeigt, wie viel Ausrüstung einsatzbereit ist, was defekt ist, welche Termine anstehen und wo etwas fehlt („Außer Betrieb“). Ein Klick auf eine Kachel führt zur passenden Liste.',
        shot: await shoot(page, [
            { target: page.getByTestId('tile-readiness'), label: 'Einsatzbereitschaft' },
            { target: page.getByTestId('tile-not-ready'), label: 'Nicht einsatzbereit' },
            { target: page.getByTestId('tile-stock'), label: 'Standorte unter Sollbestand' },
        ]),
    };
};

const deviceList: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/geraete');
    await expect(page.getByTestId('device-table')).toBeVisible();
    return {
        title: 'Ausrüstungsliste',
        text: 'Hier finden Sie alle Ausrüstung. Filtern Sie nach Typ, Standort oder Status. Ein Klick auf eine Zeile öffnet die Detailseite mit dem Verlauf. Über „Export (CSV)“ nehmen Sie die Liste mit nach Excel.',
        shot: await shoot(page, [
            { target: page.getByRole('searchbox').first().locator('xpath=..'), label: 'Suchen' },
            { target: page.getByTestId('device-table'), label: 'Ausrüstung (Zeile anklicken)' },
        ]),
    };
};

const newDevice: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/geraete/neu');
    await page.getByLabel('Nummer', { exact: false }).first().fill('M14');
    return {
        title: 'Neue Ausrüstung anlegen',
        text: 'Tragen Sie Nummer, Typ und Standort ein. „Speichern und nächstes“ legt die nächste Nummer gleich an; so erfassen Sie viele Stücke hintereinander. Viele auf einmal gehen über „Import (CSV)“.',
        shot: await shoot(page, [
            { target: page.getByLabel('Nummer', { exact: false }).first(), label: 'Nummer' },
            {
                target: page.getByRole('combobox', { name: /Ausrüstungstyp/ }).first(),
                label: 'Ausrüstungstyp',
            },
            {
                target: page.getByRole('combobox', { name: /Standort/ }).first(),
                label: 'Standort',
            },
        ]),
    };
};

const maintenance: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/wartung');
    await expect(page.getByTestId('stage-collection')).toBeVisible();
    return {
        title: 'Wartung: Sammelstelle und Werkstatt',
        text: 'Benutzte und defekte Ausrüstung liegt in der Sammelstelle. Wählen Sie aus und schicken Sie sie „Zur Werkstatt“; wenn sie gewartet ist, kommt sie mit „Zurück ins Lager“ wieder in den Dienst.',
        shot: await shoot(page, [
            { target: page.getByTestId('stage-collection'), label: 'Sammelstelle' },
            { target: page.getByTestId('to-workshop'), label: 'Zur Werkstatt' },
            { target: page.getByTestId('stage-workshop'), label: 'Werkstatt' },
        ]),
    };
};

const loans: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/verleih');
    await expect(page.getByTestId('loan-row').first()).toBeVisible();
    return {
        title: 'Verleih',
        text: 'Mit „Verleihen“ erfassen Sie, welche Ausrüstung an wen ausgeliehen ist und bis wann. Überfällige Rückgaben sind rot. Kommt sie zurück, gibt der Bote sie über den Aushang zurück (Abschnitt „Rückgabe-Aushang“), oder Sie buchen die Ausrüstung selbst ins Lager um.',
        shot: await shoot(page, [
            { target: page.getByTestId('lend-open'), label: 'Verleihen' },
            { target: page.getByTestId('loan-row').first(), label: 'Verliehen, überfällig' },
        ]),
    };
};

const returns: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/verleih');
    await expect(page.getByTestId('in-returns')).toBeVisible();
    return {
        title: 'Rückgaben bearbeiten',
        text: 'Wer Ausrüstung am Aushang zurückgibt, braucht kein Konto. Die Rückgaben erscheinen hier: In Ordnung geht ins Lager, Benutzte und Defekte in die Sammelstelle. Den Aushang (QR-Code) erzeugen oder erneuern Sie im Abschnitt „Rückgabe-Aushang“. Ohne eigenen Rückgabe-Platz hängt der Aushang an der Sammelstelle; dann kommt die Ausrüstung direkt dorthin (benutzt oder defekt) und erscheint unter „Wartung“.',
        shot: await shoot(page, [
            { target: page.getByTestId('renew-key'), label: 'Aushang erzeugen oder erneuern' },
            { target: page.getByTestId('in-returns'), label: 'Zurückgegebene Ausrüstung' },
        ]),
    };
};

const stock: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/standorte');
    await expect(page.getByTestId('stock-entry')).toBeVisible();
    return {
        title: 'Sollbestand je Fahrzeug',
        text: 'Legen Sie fest, wie viel einsatzbereite Ausrüstung ein Fahrzeug mindestens braucht. Fehlt etwas, steht das Fahrzeug als „außer Betrieb“ in der Liste und auf dem Dashboard.',
        shot: await shoot(page, [
            { target: page.getByTestId('stock-open'), label: 'Sollbestand festlegen' },
            {
                target: page.getByTestId('location-out-of-service').first(),
                label: 'Fahrzeug außer Betrieb',
            },
            { target: page.getByTestId('stock-entry'), label: 'Soll, Ist und Fehlendes' },
        ]),
    };
};

const notices: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/protokoll');
    await expect(page.getByTestId('notice-list')).toBeVisible();
    return {
        title: 'Protokoll und Hinweise',
        text: 'Wurde eine Buchung ohne Netz erfasst und passt sie nicht mehr zum aktuellen Stand, erscheint hier ein Hinweis. Öffnen Sie ihn, prüfen Sie den Fall und klicken Sie auf „Erledigen“.',
        shot: await shoot(page, [
            { target: page.getByTestId('notice-list'), label: 'Offene Hinweise' },
        ]),
    };
};

const users: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/benutzer');
    await expect(page.getByTestId('pending')).toBeVisible();
    return {
        title: 'Benutzer freischalten und Rolle zuweisen',
        text: 'Wer sich mit einem Einladungscode registriert, wartet hier auf Freischaltung. Mit „Freischalten“ vergeben Sie Anzeigenamen und Rolle (z. B. Geräteträger). Die Rolle ändern Sie später mit dem Stift oder für mehrere Personen mit „Rolle zuweisen“.',
        shot: await shoot(page, [
            { target: page.getByTestId('pending'), label: 'Neue Anmeldung freischalten' },
            {
                target: page.getByTestId('user-table'),
                label: 'Alle Benutzer (Stift = Rolle ändern)',
            },
        ]),
    };
};

const codes: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/benutzer?ansicht=einladungscodes');
    await expect(page.getByRole('button', { name: 'Neuer Einladungscode' })).toBeVisible();
    return {
        title: 'Einladungscodes',
        text: 'Neue Mitglieder registrieren sich mit einem Code. Legen Sie mit „Neuer Einladungscode“ einen an, wählen Sie die Rolle nach der Freischaltung, und drucken oder teilen Sie ihn. Der Code wird nur einmal angezeigt.',
        shot: await shoot(page, [
            {
                target: page.getByRole('button', { name: 'Neuer Einladungscode' }),
                label: 'Neuer Einladungscode',
            },
            { target: page.locator('tbody tr').first(), label: 'Gültiger Code' },
        ]),
    };
};

const posterReturn: Build = async (page) => {
    await page.setViewportSize({ width: 794, height: 1123 });
    await page.goto('/firefighting/test/verleih');
    await page.getByTestId('renew-key').click();
    const confirm = page.getByTestId('confirm-dialog');
    if (await confirm.isVisible().catch(() => false)) {
        await confirm.getByRole('button').last().click();
    }
    await expect(page.getByTestId('return-poster')).toHaveCount(1);
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => {
        document.body.style.padding = '12mm';
        document
            .querySelector('[data-testid="poster-address"]')
            ?.replaceChildren('www.prodad.de/firefighting/musterstadt/rueckgabe');
    });
    const shot = await shoot(page);
    await page.emulateMedia({ media: 'screen' });
    return {
        title: 'Aushang der Rückgabe drucken',
        text: 'Unter „Rückgabe-Aushang“ erzeugen Sie den Aushang und drucken ihn (A4). Er zeigt den QR-Code und in vier Bildern, wie die Rückgabe geht. Hängen Sie ihn dort auf, wo die Ausrüstung abgelegt wird.',
        shot,
    };
};

const poster: Build = async (page) => {
    await page.setViewportSize({ width: 794, height: 1123 });
    await page.goto('/firefighting/test/benutzer?ansicht=einladungscodes');
    await page.getByRole('button', { name: 'Neuer Einladungscode' }).click();
    const dialog = page.getByRole('dialog', { name: 'Neuer Einladungscode' });
    await dialog.getByRole('button', { name: 'Code erzeugen' }).click();
    await expect(page.getByTestId('invitation-poster')).toHaveCount(1);
    await page.emulateMedia({ media: 'print' });
    await page.evaluate(() => {
        document.body.style.padding = '12mm';
        document
            .querySelector('[data-testid="poster-address"]')
            ?.replaceChildren('www.prodad.de/firefighting/musterstadt/registrieren');
    });
    const shot = await shoot(page);
    await page.emulateMedia({ media: 'screen' });
    return {
        title: 'Aushang für Einladungscodes drucken',
        text: 'Nach dem Erzeugen können Sie den Aushang drucken: QR-Code, Code im Klartext und vier Bilder zur Registrierung. Er gilt nur so lange wie der Code.',
        shot,
    };
};

const adminNote: Build = () =>
    Promise.resolve({
        title: 'Wartung, Verleih, Sollbestand und Standorte',
        text: 'Alles zu Wartung, Verleih, Rückgaben, Sollbestand und Standorten steht in der Anleitung für Gerätewarte. Als Admin haben Sie dieselben Seiten.',
        shot: null,
    });

const settings: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/einstellungen');
    await expect(page.getByRole('heading', { level: 1, name: 'Einstellungen' })).toBeVisible();
    return {
        title: 'Einstellungen: Farbe, Logo und Mails',
        text: 'Hier legen Sie Farbe und Logo Ihrer Feuerwehr fest. Außerdem stellen Sie ein, welche Vorgänge Sie per E-Mail bekommen („Log“), und ändern Ihr Passwort.',
        shot: await shoot(page, []),
    };
};

const invitationAccept: Build = async (page) => {
    await phone(page);
    loggedIn = false;
    await page.goto(
        '/firefighting/test/einladung-annehmen#token=Einladung-aus-der-Mail_0123456789abcdefghij',
    );
    const name = page.getByLabel('Name', { exact: true });
    await expect(name).toBeVisible();
    await name.fill('Anna Beispiel');
    await page.getByLabel('Neues Passwort', { exact: true }).fill('Kxq7-Lmw2!-lang');
    const form = await shoot(page, [
        { target: name, label: 'Ihr Name (nicht die E-Mail-Adresse)' },
        { target: page.getByLabel('Neues Passwort', { exact: true }), label: 'Neues Passwort' },
        {
            target: page.getByRole('button', { name: 'Konto einrichten' }),
            label: 'Konto einrichten',
        },
    ]);
    await page.getByRole('button', { name: 'Konto einrichten' }).click();
    await expect(page.getByTestId('login-notice')).toBeVisible();
    const done = await shoot(page, [
        { target: page.getByTestId('login-notice'), label: 'Danach anmelden' },
    ]);
    loggedIn = true;
    return {
        title: 'Einladung annehmen (neue Admins)',
        text: 'Als neuer Admin bekommen Sie eine Einladung per E-Mail. Der Link darin gilt 24 Stunden; ist er abgelaufen, bitten Sie um eine neue Einladung. Tragen Sie bei „Name“ Ihren eigenen Vor- und Nachnamen ein, nicht Ihre E-Mail-Adresse: Der Name erscheint im Verlauf bei jeder Ihrer Buchungen. Wählen Sie ein Passwort und melden Sie sich danach an.',
        shot: form,
        shot2: done,
    };
};

const phoneConsole: Build = async (page) => {
    await phone(page);
    await page.goto('/firefighting/test/suchen');
    await expect(page.getByTestId('scan-button')).toBeVisible();
    const tab = page
        .getByRole('navigation', { name: 'Hauptmenü' })
        .getByRole('link', { name: 'Dashboard' });
    const first = await shoot(page, [{ target: tab, label: '„Dashboard“ in der Leiste unten' }]);
    await tab.click();
    await expect(page.getByTestId('tile-readiness')).toBeVisible();
    const menu = page.getByRole('button', { name: 'Menü öffnen' });
    const second = await shoot(page, [{ target: menu, label: '☰ „Menü“: alle Seiten' }]);
    return {
        title: 'Am Handy: Wo ist die Admin-Konsole?',
        text: 'Am Handy sehen Sie zuerst dieselben Seiten wie alle anderen: Suchen, Termine und Profil. Zur Admin-Konsole kommen Sie über „Dashboard“ in der Leiste unten; dort öffnet ☰ („Menü“) oben links alle Seiten der Konsole, z. B. Ausrüstung, Benutzer und Einstellungen. Für viele Ausrüstungen auf einmal ist der PC bequemer.',
        shot: first,
        shot2: second,
    };
};

const csvImport: Build = async (page) => {
    await desktop(page);
    await page.goto('/firefighting/test/geraete/import');
    await expect(page.getByRole('button', { name: 'Vorlage herunterladen' })).toBeVisible();
    const chooser = page.waitForEvent('filechooser');
    await page.getByRole('button', { name: /Datei wählen/ }).click();
    await (
        await chooser
    ).setFiles({
        name: 'ausruestung.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from('\uFEFFNummer;Scan-Code\r\nM30;\r\n', 'utf-8'),
    });
    await expect(page.getByTestId('summary')).toBeVisible();
    return {
        title: 'Erstbefüllung per CSV-Import',
        text: 'Zuerst Ausrüstungstypen und Standorte anlegen. Dann Ausrüstung → „Import (CSV)“ → „Vorlage herunterladen“ und in Excel ausfüllen; die Spalten Nummer und Scan-Code als Text formatieren (sonst fallen führende Nullen weg). „CSV-Datei wählen …“ zeigt eine Vorschau mit Fehlern und Warnungen; erst „Import übernehmen“ speichert.',
        shot: await shoot(page, [
            { target: page.getByTestId('import-rows'), label: 'Vorschau: Warnungen prüfen' },
            {
                target: page.getByRole('button', { name: /Import übernehmen/ }),
                label: 'Import übernehmen',
            },
        ]),
    };
};

const bulkRebook: Build = async (page) => {
    await desktop(page);
    await page.goto(`/firefighting/test/geraete?standort=${String(POOL.id)}`);
    await expect(page.getByRole('checkbox', { name: 'M20 auswählen' })).toBeVisible();
    const all = page.getByRole('checkbox', { name: 'Alle angezeigten Ausrüstungen auswählen' });
    await all.check();
    const button = page.getByRole('button', { name: 'Umbuchen / Status ändern …' });
    await expect(button).toBeVisible();
    return {
        title: 'Viele Ausrüstungen auf einmal umbuchen',
        text: 'Beispiel Fahrzeug bestücken: Ausrüstungsliste filtern (z. B. Standort „Pool“), Ausrüstungen anhaken (das Kästchen oben wählt alle angezeigten), dann „Umbuchen / Status ändern …“, den neuen Standort wählen (z. B. „HLF 20 / G1“) und speichern.',
        shot: await shoot(page, [
            { target: page.getByRole('combobox', { name: 'Standort' }), label: 'Filter Standort' },
            { target: all, label: 'Alle angezeigten auswählen' },
            { target: button, label: 'Umbuchen / Status ändern …' },
        ]),
    };
};

async function openUserDialog(page: Page, small = false): Promise<Locator> {
    await (small ? phone(page) : desktop(page));
    await page.goto('/firefighting/test/benutzer');
    await page.getByRole('button', { name: 'Max Muster bearbeiten' }).click();
    const dialog = page.getByRole('dialog', { name: 'Max Muster bearbeiten' });
    await expect(dialog.getByRole('radio', { name: 'Gerätewart' })).toBeVisible();
    return dialog;
}

const userEdit: Build = async (page) => {
    const dialog = await openUserDialog(page, true);
    const top = await shoot(page, [
        { target: dialog.getByLabel('Anzeigename'), label: 'Anzeigename' },
        { target: dialog.getByRole('group', { name: 'Rolle' }), label: 'Rolle' },
    ]);
    await dialog.evaluate((element) => {
        element.scrollTop = element.scrollHeight;
    });
    const bottom = await shoot(page, [
        {
            target: dialog.getByRole('heading', { name: 'Konto beenden' }),
            label: 'Konto beenden: sperren oder löschen',
        },
    ]);
    return {
        title: 'Benutzer bearbeiten',
        text: 'Der Stift in der Benutzerliste öffnet das Fenster „Bearbeiten“ (am PC wie am Handy): Anzeigenamen korrigieren (so erscheint die Person im Verlauf), Rolle ändern und, falls erlaubt, den Claude-Zugang setzen. Ganz unten unter „Konto beenden“ sperren Sie das Konto (lässt sich wieder entsperren) oder löschen es bei Austritt; im Verlauf steht dann „Gelöschter Benutzer“.',
        shot: top,
        shot2: bottom,
    };
};

const claude: Build = async (page) => {
    const dialog = await openUserDialog(page);
    const access = dialog.getByTestId('claude-access');
    await expect(access).toBeVisible();
    return {
        title: 'Claude-Zugang',
        text: 'Nur wenn der Super-Admin ihn für Ihre Stadt erlaubt hat; sonst fehlt die Einstellung. Im Fenster „Bearbeiten“ setzen Sie je Konto Aus, Lesen oder Ändern. Die Person fügt dann in claude.ai einen Konnektor mit der Adresse https://www.prodad.de/cgi-bin/ff-mcp.pl/<kürzel> hinzu (Kürzel = Teil der App-Adresse nach /firefighting/) und meldet sich an. Achtung: Dann gehen Daten der Stadt (Verlauf mit Namen, Kommentare, Notizen) an Anthropic. „Aus“ beendet die Verbindung sofort.',
        shot: await shoot(page, [{ target: access, label: 'Aus, Lesen oder Ändern' }]),
    };
};

const accountSwitch: Build = async (page) => {
    await page.addInitScript(() => {
        localStorage.setItem(
            'ff-accounts',
            JSON.stringify({
                format: 1,
                accounts: [
                    {
                        slug: 'nachbarstadt',
                        tenantName: 'FF Nachbarstadt',
                        accountId: 9,
                        displayName: 'Anna Beispiel',
                        roleName: 'Gerätewart',
                    },
                ],
            }),
        );
        localStorage.setItem(
            'ff-session:nachbarstadt',
            JSON.stringify({
                user: {
                    id: 9,
                    email: null,
                    name: 'Anna Beispiel',
                    display_name: 'Anna Beispiel',
                    role: 'user',
                },
                tenant: { slug: 'nachbarstadt', name: 'FF Nachbarstadt' },
            }),
        );
        localStorage.setItem(
            'ff-tenant:nachbarstadt',
            JSON.stringify({ name: 'FF Nachbarstadt', accent_color: '#1d3557' }),
        );
    });
    await desktop(page);
    await page.goto('/firefighting/test/dashboard');
    await expect(page.getByTestId('tile-readiness')).toBeVisible();
    const trigger = page.getByTitle(/Konto wechseln/).first();
    await trigger.click();
    const popover = page.getByTestId('account-switch');
    await expect(popover.getByTestId('account-nachbarstadt')).toBeVisible();
    return {
        title: 'Mehrere Städte: Konto wechseln',
        text: 'Haben Sie Konten in mehreren Feuerwehren, wechseln Sie über Ihren Namen unten im Menü. Oben links steht immer, in welcher Feuerwehr Sie gerade sind.',
        shot: await shoot(page, [
            { target: page.getByTestId('menu-tenant').first(), label: 'Aktuelle Feuerwehr' },
            { target: trigger, label: 'Ihr Name: Konto wechseln' },
            { target: popover.getByTestId('account-nachbarstadt'), label: 'Andere Feuerwehr' },
        ]),
    };
};

// --- Anleitungen -------------------------------------------------------------------------------------

interface GuideSpec {
    readonly role: Role;
    readonly file: string;
    readonly title: string;
    readonly intro: string;
    readonly can: readonly string[];
    readonly steps: readonly Build[];
}

const GUIDES: readonly GuideSpec[] = [
    {
        role: 'user',
        file: 'anleitung-benutzer.pdf',
        title: 'Benutzer',
        intro: 'Ausrüstung am Handy suchen, umbuchen und Termine ansehen.',
        can: [
            'Ausrüstung suchen oder mit der Kamera scannen',
            'Standort und Status umbuchen',
            'Termine (Wartungsdaten) ansehen',
        ],
        steps: [account, login, search, rebook, terms, offline, profile],
    },
    {
        role: 'carrier',
        file: 'anleitung-geraetetraeger.pdf',
        title: 'Geräteträger',
        intro: 'Ausrüstung am Handy buchen: umbuchen, benutzt melden und tauschen.',
        can: [
            'Ausrüstung suchen oder mit der Kamera scannen',
            'Umbuchen und Status setzen',
            'Benutzt melden und tauschen',
            'Auch ohne Netz arbeiten',
        ],
        steps: [account, login, search, rebook, used, swap, offline, terms, profile],
    },
    {
        role: 'keeper',
        file: 'anleitung-geraetewart.pdf',
        title: 'Gerätewart',
        intro: 'Ausrüstung verwalten: Wartung, Verleih, Rückgaben und Sollbestand.',
        can: [
            'Alles, was Geräteträger können (Handy)',
            'Ausrüstung anlegen, ändern und exportieren',
            'Wartung: Sammelstelle, Werkstatt und Lager',
            'Verleih und Rückgaben bearbeiten',
            'Sollbestand je Fahrzeug festlegen',
            'Hinweise im Protokoll erledigen',
        ],
        steps: [
            account,
            login,
            search,
            rebook,
            usedKeeper,
            swap,
            offline,
            dashboard,
            deviceList,
            newDevice,
            maintenance,
            loans,
            returns,
            posterReturn,
            stock,
            notices,
        ],
    },
    {
        role: 'admin',
        file: 'anleitung-admin.pdf',
        title: 'Admin',
        intro: 'Die Feuerwehr einrichten: Benutzer, Einladungen, Aushänge und Einstellungen.',
        can: [
            'Alles, was Gerätewarte können (siehe deren Anleitung)',
            'Die Admin-Konsole auch am Handy finden',
            'Viele Ausrüstungen auf einmal umbuchen, per CSV importieren',
            'Benutzer freischalten, bearbeiten und Rollen zuweisen',
            'Einladungscodes erzeugen und Aushänge drucken',
            'Einstellungen: Farbe, Logo und Mails',
            'Claude-Zugang je Konto, Konto wechseln',
        ],
        steps: [
            invitationAccept,
            login,
            phoneConsole,
            offline,
            dashboard,
            deviceList,
            newDevice,
            bulkRebook,
            csvImport,
            adminNote,
            users,
            userEdit,
            codes,
            poster,
            settings,
            claude,
            accountSwitch,
        ],
    },
];

for (const guide of GUIDES) {
    test.describe(`Anleitung ${guide.title}`, () => {
        test.use({
            api: api(guide.role),
            ...(guide.role === 'admin' && {
                tenant: { ...TENANT, body: { ...TENANT.body, claude_available: true } },
            }),
        });

        test('PDF', async ({ page }) => {
            await page.clock.setFixedTime(TODAY);
            await page.route(/\/api\/v1\/(app-icon|tenant\/logo)\/(512|192|180)/, (route) =>
                route.fulfill({ status: 200, contentType: 'image/png', body: APP_ICON }),
            );
            const steps: Step[] = [];
            for (const build of guide.steps) {
                steps.push(await build(page));
            }
            const out = await renderGuide(page, {
                file: guide.file,
                role: guide.title,
                intro: guide.intro,
                can: guide.can,
                steps,
            });
            expect(out).toContain(guide.file);
        });
    });
}
