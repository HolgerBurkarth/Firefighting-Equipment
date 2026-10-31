import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import type { ApiError, ApiResult } from '../../src/api/client';
import {
    checkSlug,
    createTenant,
    fetchTenantUsers,
    parseManagedTenant,
    parseSuperAdminList,
    parseTenantList,
    resendInvitation,
    setTenantLocked,
    type ManagedTenant,
} from '../../src/api/tenants';
import type { AdminUser } from '../../src/api/users';
import { ListStore } from '../../src/data/service';
import {
    EMAIL_MAX_LENGTH,
    TENANT_NAME_MAX_LENGTH,
    TENANT_SLUG_MAX_LENGTH,
    TENANT_SLUG_PATTERN,
    TENANT_SLUG_RESERVED,
    emailProblem,
    openInvitations,
    serviceTiles,
    slugProblem,
    suggestSlug,
    tenantNameProblem,
    tenantRows,
    tenantState,
    tenantUserRows,
} from '../../src/data/tenants';
import { respond } from './support/fetch';

function php(file: string): string {
    return readFileSync(resolve(process.cwd(), '..', file), 'utf8');
}

function phpInt(file: string, name: string): number {
    const match = new RegExp(`const int ${name} = (\\d+);`).exec(php(file));
    if (match === null) {
        throw new Error(`${name} fehlt in ${file}`);
    }
    return Number(match[1]);
}

/** Stadt wie TenantJson::tenant in PHP. */
export function tenantJson(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 1,
        slug: 'test',
        name: 'Test-Feuerwehr',
        active: true,
        created_at: '2026-10-01T08:00:00Z',
        updated_at: '2026-10-02T08:00:00Z',
        counts: { users_active: 13, admins_active: 2, admins_invited: 0 },
        ...overrides,
    };
}

function userJson(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        label: `Person ${String(id)}`,
        email: `p${String(id)}@example.org`,
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

function tenant(overrides: Record<string, unknown> = {}): ManagedTenant {
    const parsed = parseManagedTenant(tenantJson(overrides));
    if (parsed === null) {
        throw new Error('ungültig');
    }
    return parsed;
}

function user(id: number, overrides: Record<string, unknown> = {}): AdminUser {
    const list = parseSuperAdminList({
        super_admins: [{ ...userJson(id, overrides), protected: false }],
    });
    if (list?.[0] === undefined) {
        throw new Error('ungültig');
    }
    return list[0];
}

describe('Regeln wie in PHP', () => {
    it('Kürzel wie TenantSlug, Name wie TenantName, E-Mail wie EmailAddress', () => {
        const slug = php('src/Tenant/TenantSlug.php');
        expect(TENANT_SLUG_MAX_LENGTH).toBe(phpInt('src/Tenant/TenantSlug.php', 'MAX_LENGTH'));
        expect(slug).toContain(`const string PATTERN = '/${TENANT_SLUG_PATTERN.source}/D';`);
        expect(slug).toContain(
            `const array RESERVED = [${TENANT_SLUG_RESERVED.map((r) => `'${r}'`).join(', ')}];`,
        );
        expect(TENANT_NAME_MAX_LENGTH).toBe(phpInt('src/Tenant/TenantName.php', 'MAX_LENGTH'));
        expect(EMAIL_MAX_LENGTH).toBe(phpInt('src/User/EmailAddress.php', 'MAX_LENGTH'));
    });
});

describe('slugProblem', () => {
    it.each(['test', 'bad-duerrheim', 'a', 'ff-2', 'x'.repeat(50)])('%s ist gültig', (slug) => {
        expect(slugProblem(slug)).toBeNull();
    });

    it.each([
        ['', 'Bitte ein Kürzel eingeben.'],
        ['x'.repeat(51), 'Höchstens 50 Zeichen (jetzt 51).'],
        ['service', 'Das Kürzel „service“ ist reserviert.'],
        ['SERVICE', 'Das Kürzel „service“ ist reserviert.'],
        ['Test', 'Bitte nur Kleinbuchstaben, z. B. „test“.'],
    ])('%s → %s', (slug, message) => {
        expect(slugProblem(slug)).toBe(message);
    });

    it.each(['-test', 'test-', 'a--b', 'bad dürrheim', 'tést', 'a_b', 'Te st'])(
        '%s hat ein ungültiges Format',
        (slug) => {
            expect(slugProblem(slug)).toMatch(/^Nur Kleinbuchstaben/);
        },
    );

    it('Länge vor reserviert, wie TenantSlug::fromString', () => {
        expect(slugProblem('S'.repeat(51))).toBe('Höchstens 50 Zeichen (jetzt 51).');
    });
});

describe('suggestSlug', () => {
    it.each([
        ['Feuerwehr Bad Dürrheim', 'bad-duerrheim'],
        ['Freiwillige Feuerwehr Immendingen', 'immendingen'],
        ['FF Möhringen', 'moehringen'],
        ['Stadt Tuttlingen', 'tuttlingen'],
        ['Gemeinde Gößweinstein', 'goessweinstein'],
        ['Feuerwehr', 'feuerwehr'],
        ['Test-Feuerwehr', 'test-feuerwehr'],
        ['  Café   Crème  ', 'cafe-creme'],
        ['Löschzug 2 / Nord', 'loeschzug-2-nord'],
        ['ÄÖÜ', 'aeoeue'],
        ['', ''],
        ['!!!', ''],
    ])('%s → %s', (name, slug) => {
        expect(suggestSlug(name)).toBe(slug);
    });

    it('höchstens 50 Zeichen, ohne Bindestrich am Ende', () => {
        const slug = suggestSlug(`${'a'.repeat(49)} b`);
        expect(slug).toBe('a'.repeat(49));
        expect(slugProblem(suggestSlug('Feuerwehr ' + 'Sehr Langer Name '.repeat(5)))).toBeNull();
    });

    it('jeder nicht leere Vorschlag ist ein gültiges Kürzel', () => {
        for (const name of ['Ü-Bach', 'Œuvre', 'Straße 1', 'Ærø', 'São Paulo', '1. Zug']) {
            const slug = suggestSlug(name);
            expect(slug === '' || slugProblem(slug) === null, `${name} → ${slug}`).toBe(true);
        }
    });
});

describe('tenantNameProblem und emailProblem', () => {
    it('Name', () => {
        expect(tenantNameProblem('  Test  ')).toBeNull();
        expect(tenantNameProblem(' ')).toBe('Bitte den Namen der Stadt eingeben.');
        expect(tenantNameProblem('x'.repeat(101))).toBe('Höchstens 100 Zeichen (jetzt 101).');
        expect(tenantNameProblem('a\u0007b')).toBe('Keine Zeilenumbrüche oder Steuerzeichen.');
    });

    it.each(['name@beispiel.de', ' Name@Beispiel.DE ', 'a.b+c@sub.example.org', 'ü@bä.de'])(
        '%s ist gültig',
        (email) => {
            expect(emailProblem(email)).toBeNull();
        },
    );

    it.each(['', 'name', 'name@', 'name@beispiel', 'a b@c.de', 'a@b@c.de', 'a@.de', 'a@b..de'])(
        '%s ist ungültig',
        (email) => {
            expect(emailProblem(email)).not.toBeNull();
        },
    );
});

describe('API', () => {
    it('parseTenantList liest Städte mit Zählern', () => {
        expect(parseTenantList({ tenants: [tenantJson()] })).toEqual([
            {
                id: 1,
                slug: 'test',
                name: 'Test-Feuerwehr',
                active: true,
                createdAt: '2026-10-01T08:00:00Z',
                updatedAt: '2026-10-02T08:00:00Z',
                usersActive: 13,
                adminsActive: 2,
                adminsInvited: 0,
            },
        ]);
    });

    it.each([
        ['ohne counts', tenantJson({ counts: null })],
        [
            'negative Zahl',
            tenantJson({ counts: { users_active: -1, admins_active: 0, admins_invited: 0 } }),
        ],
        ['active als Zahl', tenantJson({ active: 1 })],
        ['Zeit ohne Z', tenantJson({ created_at: '2026-10-01 08:00:00' })],
    ])('lehnt %s ab', (_, json) => {
        expect(parseTenantList({ tenants: [json] })).toBeNull();
    });

    it('Super-Admins brauchen protected', () => {
        expect(parseSuperAdminList({ super_admins: [userJson(1)] })).toBeNull();
        expect(
            parseSuperAdminList({ super_admins: [{ ...userJson(1), protected: true }] })?.[0]
                ?.protected,
        ).toBe(true);
    });

    it('Kürzel prüfen sendet den Wert im Query-String', async () => {
        const { fetch, requests } = respond(200, {
            slug: 'bad dürrheim',
            valid: false,
            available: false,
            message: 'Ungültiges Kürzel …',
            suggestion: null,
        });
        const result = await checkSlug('bad dürrheim', fetch);
        expect(result.ok && result.data.valid).toBe(false);
        expect(requests[0]?.url).toMatch(
            /api\/v1\/tenants\/slug-available\?slug=bad\+d%C3%BCrrheim$/,
        );
    });

    it('Stadt anlegen sendet slug, name und admin_email', async () => {
        const { fetch, requests } = respond(201, {
            tenant: tenantJson({ id: 5, slug: 'neu' }),
            user: userJson(9),
            mail_sent: false,
        });
        const result = await createTenant(
            { slug: 'neu', name: 'Neu', adminEmail: 'a@b.de' },
            fetch,
        );
        expect(result.ok && result.data.mailSent).toBe(false);
        expect(result.ok && result.data.tenant.id).toBe(5);
        expect(requests[0]?.method).toBe('POST');
        expect(requests[0]?.body).toEqual({ slug: 'neu', name: 'Neu', admin_email: 'a@b.de' });
    });

    it('Sperren, Konten einer Stadt und erneut senden', async () => {
        const lock = respond(200, { tenant: tenantJson({ active: false }) });
        expect((await setTenantLocked(1, true, lock.fetch)).ok).toBe(true);
        expect(lock.requests[0]?.url).toMatch(/tenants\/1\/lock$/);
        expect(lock.requests[0]?.body).toEqual({});

        const users = respond(200, { users: [userJson(4)] });
        expect((await fetchTenantUsers(1, 'invited', users.fetch)).ok).toBe(true);
        expect(users.requests[0]?.url).toMatch(/tenants\/1\/users\?status=invited$/);

        const resend = respond(200, { user: userJson(4), mail_sent: true });
        expect((await resendInvitation(1, 4, resend.fetch)).ok).toBe(true);
        expect(resend.requests[0]?.url).toMatch(/tenants\/1\/users\/4\/resend-invitation$/);
    });
});

describe('Liste und Kacheln', () => {
    const first = tenant({ id: 1, slug: 'test', name: 'Test-Feuerwehr' });
    const second = tenant({
        id: 2,
        slug: 'bad-duerrheim',
        name: 'Feuerwehr Bad Dürrheim',
        counts: { users_active: 0, admins_active: 0, admins_invited: 1 },
    });
    const tenants = [
        first,
        second,
        tenant({
            id: 3,
            slug: 'alt',
            name: 'Altstadt',
            active: false,
            counts: { users_active: 4, admins_active: 1, admins_invited: 2 },
        }),
    ];

    it('Status: gesperrt, Einladung offen, aktiv', () => {
        expect(tenants.map(tenantState)).toEqual(['active', 'invitation', 'locked']);
    });

    it('Suche in Name und Kürzel, Sortierung mit Richtung', () => {
        expect(tenantRows(tenants, 'durrh', 'name', 'asc').map((t) => t.id)).toEqual([2]);
        expect(tenantRows(tenants, 'bad-duer', 'name', 'asc').map((t) => t.id)).toEqual([2]);
        expect(tenantRows(tenants, '', 'name', 'asc').map((t) => t.id)).toEqual([3, 2, 1]);
        expect(tenantRows(tenants, '', 'users', 'desc').map((t) => t.id)).toEqual([1, 3, 2]);
        expect(tenantRows(tenants, '', 'status', 'asc').map((t) => t.id)).toEqual([1, 2, 3]);
        expect(tenantRows(tenants, '', 'address', 'asc').map((t) => t.slug)).toEqual([
            'alt',
            'bad-duerrheim',
            'test',
        ]);
    });

    it('Kacheln: aktive und gesperrte Städte, Einladungen, Benutzer', () => {
        expect(serviceTiles(tenants)).toEqual({ active: 2, locked: 1, invited: 3, users: 17 });
    });

    it('offene Einladungen: nur eingeladene, nicht gesperrte Admins, älteste zuerst', () => {
        const invitations = openInvitations([
            {
                tenant: first,
                users: [
                    user(5, { created_at: '2026-10-21T09:00:00Z' }),
                    user(6, { locked: true }),
                    user(7, { status: 'active' }),
                ],
            },
            {
                tenant: second,
                users: [user(8, { created_at: '2026-10-19T09:00:00Z' })],
            },
        ]);
        expect(invitations.map((entry) => entry.user.id)).toEqual([8, 5]);
    });

    it('Konten einer Stadt: Suche in Name und E-Mail', () => {
        const users = [user(1, { label: 'Zoë' }), user(2, { label: 'Anna', email: 'x@y.de' })];
        expect(tenantUserRows(users, '').map((u) => u.id)).toEqual([2, 1]);
        expect(tenantUserRows(users, 'zoe').map((u) => u.id)).toEqual([1]);
        expect(tenantUserRows(users, 'x@y').map((u) => u.id)).toEqual([2]);
    });
});

describe('ListStore', () => {
    it('behält die Liste bei einem Fehler und leert sie mit clear', async () => {
        const network: ApiError = {
            code: 'network',
            message: null,
            status: null,
            retryAfterSeconds: null,
            details: null,
        };
        let next: ApiResult<readonly number[]> = { ok: true, status: 200, data: [1, 2] };
        const store = new ListStore<number>(() => Promise.resolve(next));
        await store.refresh('open');
        expect(store.state.items).toEqual([1, 2]);
        next = { ok: false, error: network };
        await store.refresh('changed');
        expect(store.state.items).toEqual([1, 2]);
        expect(store.state.error).toEqual(network);
        store.clear();
        expect(store.state).toEqual({ items: null, loading: false, error: null });
    });
});
