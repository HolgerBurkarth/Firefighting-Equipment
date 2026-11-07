import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { parseTenant } from '../../src/api/tenant';
import { parseManagedTenant, setTenantUserClaudeAccess } from '../../src/api/tenants';
import {
    CLAUDE_ACCESS_LABELS,
    CLAUDE_ACCESS_VALUES,
    endUserClaudeConnections,
    parseAdminUser,
    setUserClaudeAccess,
    type AdminUser,
} from '../../src/api/users';
import { claudeAccessBlocker, claudeChip, claudeConnectionText } from '../../src/data/users';
import { respond } from './support/fetch';

/** Konto wie UserJson::user (seit Schritt M1 mit Claude-Zugang). */
function json(id: number, overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id,
        label: `Person ${String(id)}`,
        email: `person${String(id)}@example.org`,
        name: `Person ${String(id)}`,
        display_name: `Person ${String(id)}`,
        role: 'user',
        role_label: 'Benutzer',
        role_id: 2,
        role_name: 'Benutzer',
        status: 'active',
        status_label: 'aktiv',
        locked: false,
        email_verified_at: '2026-10-01T10:00:00Z',
        created_at: '2026-10-01T09:00:00Z',
        invitation_code: null,
        claude_access: 'off',
        claude_access_label: 'Aus',
        claude_connections: { count: 0, last_used_at: null },
        ...overrides,
    };
}

function user(id: number, overrides: Record<string, unknown> = {}): AdminUser {
    const parsed = parseAdminUser(json(id, overrides));
    if (parsed === null) {
        throw new Error('ungültiges Konto');
    }
    return parsed;
}

describe('Claude-Zugang in der Benutzerverwaltung', () => {
    it('Werte und Bezeichnungen wie ClaudeAccess in PHP', () => {
        // Unter happy-dom ist import.meta.url keine Datei-Adresse; Vitest läuft in frontend/.
        const php = readFileSync(resolve(process.cwd(), '../src/Claude/ClaudeAccess.php'), 'utf8');
        const values = [...php.matchAll(/case \w+ = '(\w+)';/g)].map((match) => match[1]);
        expect(values).toEqual([...CLAUDE_ACCESS_VALUES]);
        const labels = [...php.matchAll(/self::\w+ => '([^']+)'/g)].map((match) => match[1]);
        expect(labels).toEqual(CLAUDE_ACCESS_VALUES.map((value) => CLAUDE_ACCESS_LABELS[value]));
    });

    it('liest Zugang und Verbindungen; ohne die Felder „aus“ ohne Verbindung', () => {
        const connected = user(3, {
            claude_access: 'write',
            claude_connections: { count: 2, last_used_at: '2026-11-07T08:00:00Z' },
        });
        expect([connected.claudeAccess, connected.claudeConnections]).toEqual([
            'write',
            { count: 2, lastUsedAt: '2026-11-07T08:00:00Z' },
        ]);
        const old = json(3);
        delete old['claude_access'];
        delete old['claude_connections'];
        expect(parseAdminUser(old)?.claudeAccess).toBe('off');
        expect(parseAdminUser(old)?.claudeConnections).toEqual({ count: 0, lastUsedAt: null });

        expect(parseAdminUser(json(3, { claude_access: 'admin' }))).toBeNull();
        expect(
            parseAdminUser(json(3, { claude_connections: { count: -1, last_used_at: null } })),
        ).toBeNull();
        expect(
            parseAdminUser(json(3, { claude_connections: { count: 1, last_used_at: null } })),
        ).toBeNull();
        expect(
            parseAdminUser(json(3, { claude_connections: { count: 0, last_used_at: 'gestern' } })),
        ).toBeNull();
    });

    it('Chip nur mit Zugang, Text nur mit Verbindung', () => {
        expect(claudeChip(user(1))).toBeNull();
        expect(claudeChip(user(1, { claude_access: 'read' }))).toBe('Claude: Lesen');
        expect(claudeChip(user(1, { claude_access: 'write' }))).toBe('Claude: Ändern');

        const now = new Date('2026-11-07T08:05:00Z');
        expect(claudeConnectionText(user(1), now)).toBeNull();
        expect(
            claudeConnectionText(
                user(1, { claude_connections: { count: 1, last_used_at: '2026-11-07T08:00:00Z' } }),
                now,
            ),
        ).toBe('verbunden · zuletzt genutzt vor 5 Min.');
        expect(
            claudeConnectionText(
                user(1, { claude_connections: { count: 2, last_used_at: '2026-11-07T08:00:00Z' } }),
                now,
            ),
        ).toBe('2 Verbindungen · zuletzt genutzt vor 5 Min.');
    });

    it('Auswahl gesperrt am eigenen Konto, bei Admins und nicht aktiven Konten (F12)', () => {
        expect(claudeAccessBlocker(user(1), 2)).toBeNull();
        expect(claudeAccessBlocker(user(1, { claude_access: 'read' }), 2)).toBeNull();
        expect(claudeAccessBlocker(user(2, { role: 'admin' }), 2)).toBe(
            'Den eigenen Claude-Zugang kann man nicht ändern.',
        );
        expect(claudeAccessBlocker(user(1, { role: 'admin' }), 2)).toBe(
            'Für Admins setzt den Claude-Zugang der Super-Admin.',
        );
        expect(claudeAccessBlocker(user(1, { status: 'invited' }), 2)).toBe(
            'Den Claude-Zugang gibt es nur für aktive Konten.',
        );
    });

    it('sendet access bzw. {} an die Routen der Stadt und des Super-Admins', async () => {
        const { requests, fetch: fetchFn } = respond(200, {
            user: json(4, { claude_access: 'read' }),
        });

        const set = await setUserClaudeAccess(4, 'read', fetchFn);
        expect(set.ok && set.data.claudeAccess).toBe('read');
        await endUserClaudeConnections(4, fetchFn);
        await setTenantUserClaudeAccess(9, 4, 'write', fetchFn);

        expect(
            requests.map((request) => [
                request.method,
                request.url.replace(/^.*\/api\/v1\//, ''),
                request.body,
            ]),
        ).toEqual([
            ['POST', 'users/4/claude-access', { access: 'read' }],
            ['POST', 'users/4/claude-connections/end', {}],
            ['POST', 'tenants/9/users/4/claude-access', { access: 'write' }],
        ]);
    });
});

describe('Claude verfügbar je Stadt', () => {
    it('GET tenant und TenantJson: claude_available, fehlt = nein', () => {
        const tenant = {
            slug: 'test',
            name: 'Feuerwehr Test',
            accent_color: '#b71c1c',
            logo_urls: null,
        };
        expect(parseTenant({ ...tenant, claude_available: true })?.claudeAvailable).toBe(true);
        expect(parseTenant(tenant)?.claudeAvailable).toBe(false);
        expect(parseTenant({ ...tenant, claude_available: 'ja' })).toBeNull();

        const managed = {
            id: 1,
            slug: 'test',
            name: 'Feuerwehr Test',
            active: true,
            created_at: '2026-10-01T09:00:00Z',
            updated_at: '2026-10-01T09:00:00Z',
            counts: { users_active: 1, admins_active: 1, admins_invited: 0 },
        };
        expect(parseManagedTenant({ ...managed, claude_available: true })?.claudeAvailable).toBe(
            true,
        );
        expect(parseManagedTenant(managed)?.claudeAvailable).toBe(false);
    });
});
