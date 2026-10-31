import type { MockRequest, MockResponse } from '../fixtures';

/** Stadt wie TenantJson::tenant in PHP. */
export function tenantJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        slug: `stadt-${String(id)}`,
        name: `Stadt ${String(id)}`,
        active: true,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-01T08:00:00Z',
        counts: { users_active: 0, admins_active: 1, admins_invited: 0 },
        ...overrides,
    };
}

/** Konto wie UserJson::user (Konten einer Stadt), Standard: eingeladener Admin. */
export function accountJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return {
        id,
        label: `admin${String(id)}@example.org`,
        email: `admin${String(id)}@example.org`,
        name: null,
        display_name: null,
        role: 'admin',
        role_label: 'Admin',
        status: 'invited',
        status_label: 'eingeladen',
        locked: false,
        email_verified_at: null,
        created_at: '2026-10-20T09:00:00Z',
        ...overrides,
    };
}

/** Super-Admin wie SuperAdminJson (UserJson plus `protected`). */
export function superAdminJson(
    id: number,
    overrides: Record<string, unknown> = {},
): Record<string, unknown> {
    return accountJson(id, {
        label: `Super ${String(id)}`,
        name: `Super ${String(id)}`,
        display_name: `Super ${String(id)}`,
        role: 'superadmin',
        role_label: 'Super-Admin',
        status: 'active',
        status_label: 'aktiv',
        email_verified_at: '2026-01-01T08:00:00Z',
        protected: false,
        ...overrides,
    });
}

/**
 * Drei Städte wie im Mockup „SuperAdmin“: Immendingen und Test aktiv, Bad Dürrheim mit offener
 * Einladung (Konto 31).
 */
export const TENANTS: readonly Record<string, unknown>[] = [
    tenantJson(1, {
        slug: 'immendingen',
        name: 'Feuerwehr Immendingen',
        counts: { users_active: 24, admins_active: 1, admins_invited: 0 },
    }),
    tenantJson(2, {
        slug: 'test',
        name: 'Test-Feuerwehr',
        counts: { users_active: 13, admins_active: 2, admins_invited: 0 },
    }),
    tenantJson(3, {
        slug: 'bad-duerrheim',
        name: 'Feuerwehr Bad Dürrheim',
        counts: { users_active: 0, admins_active: 0, admins_invited: 1 },
    }),
];

/** Abfragen des Dashboards: alle Städte und die eingeladenen Konten der Städte mit Einladungen. */
export function serviceOverview(
    tenants: readonly Record<string, unknown>[] = TENANTS,
    invited: Readonly<Record<number, readonly Record<string, unknown>[]>> = {
        3: [accountJson(31, { email: 'kommandant@bad-duerrheim.de' })],
    },
): Record<string, MockResponse | ((request: MockRequest) => MockResponse)> {
    const mocks: Record<string, MockResponse | ((request: MockRequest) => MockResponse)> = {
        'GET api/v1/tenants': { status: 200, body: { tenants } },
    };
    for (const [id, users] of Object.entries(invited)) {
        mocks[`GET api/v1/tenants/${id}/users`] = { status: 200, body: { users } };
    }
    return mocks;
}

export const SERVICE_OVERVIEW = serviceOverview();

type Handlers = Record<string, (request: MockRequest) => MockResponse>;

/** Simulierter Server des Super-Admins mit Zustand (je Test neu, siehe `delegate`). */
export interface ServiceServer {
    readonly handlers: Handlers;
    readonly tenants: Record<string, unknown>[];
    /** Konten je Stadt (ID der Stadt → Konten wie UserJson). */
    readonly users: Map<number, Record<string, unknown>[]>;
    /** Ergebnis des nächsten Mailversands (`mail_sent`). */
    mailSent: boolean;
}

function counts(users: readonly Record<string, unknown>[]): Record<string, number> {
    const live = users.filter((user) => user['status'] !== 'deleted');
    return {
        users_active: live.filter((user) => user['status'] === 'active' && user['locked'] !== true)
            .length,
        admins_active: live.filter(
            (user) =>
                user['role'] === 'admin' && user['status'] === 'active' && user['locked'] !== true,
        ).length,
        admins_invited: live.filter(
            (user) => user['role'] === 'admin' && user['status'] === 'invited',
        ).length,
    };
}

const SLUG_TAKEN = {
    status: 409,
    body: { error: 'slug_taken', message: 'Das Kürzel „test“ ist bereits vergeben.' },
} as const;

/**
 * Städte wie TENANTS (Bad Dürrheim mit eingeladenem Konto 31, Test mit zwei Admins und elf
 * Benutzern) und die Routen des Super-Admins für Städte; neue IDs ab 100.
 */
export function serviceServer(): ServiceServer {
    let nextId = 100;
    const users = new Map<number, Record<string, unknown>[]>([
        [1, [accountJson(11, { status: 'active', status_label: 'aktiv', label: 'Kommandant' })]],
        [
            2,
            [
                accountJson(21, {
                    label: 'Thomas Keller',
                    name: 'Thomas Keller',
                    display_name: 'Thomas Keller',
                    email: 'thomas@example.org',
                    status: 'active',
                    status_label: 'aktiv',
                }),
                accountJson(22, {
                    label: 'Anna Berg',
                    email: 'anna@example.org',
                    status: 'active',
                    status_label: 'aktiv',
                }),
                accountJson(23, {
                    label: 'Max Neu',
                    email: 'max@example.org',
                    role: 'user',
                    role_label: 'Benutzer',
                    status: 'pending',
                    status_label: 'wartet auf Freischaltung',
                }),
            ],
        ],
        [3, [accountJson(31, { email: 'kommandant@bad-duerrheim.de' })]],
    ]);
    const tenants = TENANTS.map((tenant) => ({ ...tenant }));
    const server: ServiceServer = { handlers: {}, tenants, users, mailSent: true };

    const find = (request: MockRequest, index = 3): Record<string, unknown> | undefined => {
        const id = Number(request.path.split('/')[index]);
        return tenants.find((tenant) => tenant['id'] === id);
    };
    const notFound: MockResponse = {
        status: 404,
        body: { error: 'not_found', message: 'Diese Stadt gibt es nicht.' },
    };
    /** Zähler aus den Konten, soweit für die Stadt bekannt (sonst wie angegeben). */
    const withCounts = (tenant: Record<string, unknown>): Record<string, unknown> => {
        const list = users.get(tenant['id'] as number);
        return list === undefined || tenant['id'] === 1 || tenant['id'] === 2
            ? tenant
            : { ...tenant, counts: counts(list) };
    };

    Object.assign(server.handlers, {
        'GET api/v1/tenants': () => ({
            status: 200,
            body: { tenants: tenants.map(withCounts) },
        }),
        'GET api/v1/tenants/slug-available': (request: MockRequest) => {
            const slug = new URL(request.url).searchParams.get('slug') ?? '';
            const available = !tenants.some((tenant) => tenant['slug'] === slug);
            return {
                status: 200,
                body: {
                    slug,
                    valid: true,
                    available,
                    message: available
                        ? 'Das Kürzel ist verfügbar.'
                        : 'Das Kürzel ist bereits vergeben.',
                    suggestion: null,
                },
            };
        },
        'POST api/v1/tenants': (request: MockRequest) => {
            const body = request.body as { slug: string; name: string; admin_email: string };
            if (tenants.some((tenant) => tenant['slug'] === body.slug)) {
                return SLUG_TAKEN;
            }
            const id = nextId++;
            const user = accountJson(nextId++, {
                email: body.admin_email,
                label: body.admin_email,
            });
            users.set(id, [user]);
            const tenant = tenantJson(id, {
                slug: body.slug,
                name: body.name,
                created_at: '2026-10-31T20:00:00Z',
            });
            tenants.push(tenant);
            return {
                status: 201,
                body: { tenant: withCounts(tenant), user, mail_sent: server.mailSent },
            };
        },
    });
    // 100: die erste neu angelegte Stadt (die Routen stehen vorher fest, siehe `delegate`).
    for (const id of [1, 2, 3, 100]) {
        server.handlers[`GET api/v1/tenants/${String(id)}/users`] = (request) => {
            const status = new URL(request.url).searchParams.get('status');
            const list = (users.get(id) ?? []).filter((user) =>
                status === null ? user['status'] !== 'deleted' : user['status'] === status,
            );
            return find(request) === undefined ? notFound : { status: 200, body: { users: list } };
        };
    }
    server.handlers['POST api/v1/tenants/3/users/31/resend-invitation'] = () => ({
        status: 200,
        body: { user: users.get(3)?.[0], mail_sent: server.mailSent },
    });
    return server;
}
