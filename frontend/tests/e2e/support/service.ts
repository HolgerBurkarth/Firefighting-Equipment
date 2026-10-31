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
