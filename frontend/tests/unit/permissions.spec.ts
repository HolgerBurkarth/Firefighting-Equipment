// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { parseAuthState } from '../../src/api/auth';
import {
    ADMIN_PERMISSIONS,
    allows,
    AREA_MAX_LEVELS,
    can,
    parsePermissions,
    PERMISSION_ACTIONS,
    permissionsOf,
    USER_PERMISSIONS,
} from '../../src/api/permissions';
import { meBody, permissionsJson } from './support/me';

const catalog = JSON.parse(
    readFileSync(
        new URL('../../../tests/Fixtures/permission-catalog.json', import.meta.url),
        'utf8',
    ),
) as {
    areas: { key: string; levels: string[] }[];
    action_groups: { actions: { key: string }[] }[];
};
const defaultRolesPhp = readFileSync(
    new URL('../../../src/Role/DefaultRoles.php', import.meta.url),
    'utf8',
);

describe('Katalog', () => {
    it('Bereiche und höchste Stufen wie GET permission-catalog (gemeinsame Datei)', () => {
        expect(
            Object.fromEntries(
                catalog.areas.map((area) => [area.key, area.levels.at(-1)] as const),
            ),
        ).toEqual(AREA_MAX_LEVELS);
        expect(Object.keys(AREA_MAX_LEVELS)).toEqual(catalog.areas.map((area) => area.key));
    });

    it('Aktionen wie der Katalog, in seiner Reihenfolge', () => {
        expect(PERMISSION_ACTIONS).toEqual(
            catalog.action_groups.flatMap((group) => group.actions.map((action) => action.key)),
        );
    });

    it('Standard-Rolle Benutzer wie DefaultRoles in PHP', () => {
        const areas = /USER_AREAS = \[([^\]]*)\]/.exec(defaultRolesPhp)?.[1] ?? '';
        const actions = /USER_ACTIONS = \[([^\]]*)\]/.exec(defaultRolesPhp)?.[1] ?? '';
        expect(
            Object.fromEntries(
                [...areas.matchAll(/'([a-z_]+)' => '([a-z]+)'/g)].map((m) => [m[1], m[2]]),
            ),
        ).toEqual(USER_PERMISSIONS.areas);
        expect([...actions.matchAll(/'([a-z_.]+)'/g)].map((m) => m[1])).toEqual(
            USER_PERMISSIONS.actions,
        );
    });

    it('Admin hat alle Bereiche in der höchsten Stufe und alle Aktionen', () => {
        expect(ADMIN_PERMISSIONS.areas).toEqual(AREA_MAX_LEVELS);
        expect(ADMIN_PERMISSIONS.actions).toEqual(PERMISSION_ACTIONS);
    });
});

describe('parsePermissions', () => {
    it('liest Bereiche, Aktionen, Datenfilter und Kennung', () => {
        expect(
            parsePermissions(
                permissionsJson({ devices: 'write', log: 'read' }, ['device.edit'], true),
            ),
        ).toEqual({
            areas: { devices: 'write', log: 'read' },
            actions: ['device.edit'],
            filtered: true,
            version: 'v-{"devices":"write","log":"read"}',
        });
    });

    it('unbekannte Bereiche, Stufen und Aktionen geben kein Recht (neuerer Server)', () => {
        const parsed = parsePermissions(
            permissionsJson({ maintenance: 'write', devices: 'admin', search: 'write' } as never, [
                'lend',
                'book.move',
            ]),
        );
        expect(parsed?.areas).toEqual({ search: 'read' });
        expect(parsed?.actions).toEqual(['book.move']);
    });

    it('fehlt oder null: null; sonst unbrauchbar (undefined)', () => {
        expect(parsePermissions(undefined)).toBeNull();
        expect(parsePermissions(null)).toBeNull();
        expect(parsePermissions({ areas: [], actions: [], filtered: false })).toBeUndefined();
        expect(
            parsePermissions({ areas: {}, actions: 'x', filtered: false, version: 'v' }),
        ).toBeUndefined();
    });

    it('auth/me mit unbrauchbaren Rechten ist keine gültige Antwort', () => {
        expect(parseAuthState(meBody('user', { areas: {} }))).toBeNull();
    });
});

describe('permissionsOf', () => {
    it('Rechte vom Server gehen vor', () => {
        const state = parseAuthState(meBody('user', permissionsJson({ devices: 'read' })));
        expect(state).not.toBeNull();
        expect(permissionsOf(state)?.areas).toEqual({ devices: 'read' });
    });

    it('ohne Rechte vom Server die der Kontoart; Super-Admin und ohne Sitzung null', () => {
        expect(permissionsOf(parseAuthState(meBody('admin')))).toBe(ADMIN_PERMISSIONS);
        expect(permissionsOf(parseAuthState(meBody('user')))).toBe(USER_PERMISSIONS);
        expect(permissionsOf(parseAuthState(meBody('superadmin')))).toBeNull();
        expect(permissionsOf(null)).toBeNull();
    });

    it('allows: Ändern schließt Ansehen ein; can', () => {
        const permissions = parsePermissions(
            permissionsJson({ devices: 'write', log: 'read' }, ['csv.export']),
        );
        expect(permissions).toBeTruthy();
        const p = permissions ?? null;
        expect(allows(p, 'devices')).toBe(true);
        expect(allows(p, 'devices', 'write')).toBe(true);
        expect(allows(p, 'log', 'write')).toBe(false);
        expect(allows(p, 'users')).toBe(false);
        expect(can(p, 'csv.export')).toBe(true);
        expect(can(p, 'device.edit')).toBe(false);
        expect(allows(null, 'search')).toBe(false);
        expect(can(null, 'book.move')).toBe(false);
    });
});
