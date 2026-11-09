import { isRecord } from './client';
import type { AuthState, UserRole } from './auth';

/**
 * Rechte der Rolle in der App (Plan Phase 1, Schritt 1.7b): aus `permissions` von auth/me
 * (AuthJson::permissions), sonst aus der Kontoart (Sitzung einer App vor 1.7, Start offline). Die App
 * richtet Seiten, Menü, Leiste und Knöpfe danach; verbindlich bleibt der Server (403).
 */

/** Bereiche wie Area in PHP; Stufe höchstens wie dort `maxLevel` (per Test gleich dem Katalog). */
export type PermissionArea =
    | 'dashboard'
    | 'search'
    | 'devices'
    | 'locations'
    | 'device_types'
    | 'due'
    | 'log'
    | 'users'
    | 'settings'
    | 'maintenance'
    | 'loans';

export type PermissionLevel = 'read' | 'write';

/** Aktionen wie Action in PHP (per Test gleich dem Katalog). */
export type PermissionAction =
    | 'book.move'
    | 'book.status'
    | 'device.used'
    | 'device.swap'
    | 'stock.override'
    | 'device.create'
    | 'device.edit'
    | 'device.retire'
    | 'device.code'
    | 'devices.bulk'
    | 'csv.import'
    | 'csv.export'
    | 'mail.log';

/** Höchste Stufe je Bereich (Area::maxLevel); die Reihenfolge ist die des Katalogs. */
export const AREA_MAX_LEVELS: Readonly<Record<PermissionArea, PermissionLevel>> = {
    dashboard: 'read',
    search: 'read',
    devices: 'write',
    locations: 'write',
    device_types: 'write',
    due: 'write',
    log: 'write',
    users: 'write',
    settings: 'write',
    maintenance: 'write',
    loans: 'write',
};

/** Einschränkungen wie Restriction in PHP (per Test gleich dem Katalog). */
export type PermissionRestriction = 'single_use_used_only';

export const PERMISSION_RESTRICTIONS: readonly PermissionRestriction[] = ['single_use_used_only'];

export const PERMISSION_ACTIONS: readonly PermissionAction[] = [
    'book.move',
    'book.status',
    'device.used',
    'device.swap',
    'stock.override',
    'device.create',
    'device.edit',
    'device.retire',
    'device.code',
    'devices.bulk',
    'csv.import',
    'csv.export',
    'mail.log',
];

const AREAS = Object.keys(AREA_MAX_LEVELS) as PermissionArea[];

export interface Permissions {
    /** Bereich → Stufe; fehlt = kein Zugriff. „Ändern“ schließt „Ansehen“ ein. */
    readonly areas: Readonly<Partial<Record<PermissionArea, PermissionLevel>>>;
    readonly actions: readonly PermissionAction[];
    /** Einschränkungen der Rolle (Phase 2); fehlt im Feld = keine. */
    readonly restrictions: readonly PermissionRestriction[];
    /** Rolle mit Datenfilter „Sieht nur …“. */
    readonly filtered: boolean;
    /** Kennung des Stands (`permissions.version`); null = nicht vom Server, aus der Kontoart. */
    readonly version: string | null;
}

function isArea(value: string): value is PermissionArea {
    return (AREAS as readonly string[]).includes(value);
}

function isAction(value: unknown): value is PermissionAction {
    return typeof value === 'string' && (PERMISSION_ACTIONS as readonly string[]).includes(value);
}

/**
 * `permissions` aus auth/me: null (unter `service` bzw. fehlt), sonst die Rechte; undefined = Antwort
 * unbrauchbar. Unbekannte Bereiche, Stufen und Aktionen (z. B. eines neueren Servers) geben kein
 * Recht, wie `Permissions::fromRows` in PHP.
 */
export function parsePermissions(json: unknown): Permissions | null | undefined {
    if (json === null || json === undefined) {
        return null;
    }
    if (
        !isRecord(json) ||
        !isRecord(json['areas']) ||
        !Array.isArray(json['actions']) ||
        typeof json['filtered'] !== 'boolean' ||
        typeof json['version'] !== 'string'
    ) {
        return undefined;
    }
    const areas: Partial<Record<PermissionArea, PermissionLevel>> = {};
    for (const [key, level] of Object.entries(json['areas'])) {
        if (isArea(key) && (level === 'read' || level === 'write')) {
            areas[key] = level === 'write' && AREA_MAX_LEVELS[key] === 'read' ? 'read' : level;
        }
    }
    const actions = PERMISSION_ACTIONS.filter((action) =>
        (json['actions'] as unknown[]).some((value) => isAction(value) && value === action),
    );
    const restrictions = PERMISSION_RESTRICTIONS.filter(
        (restriction) =>
            Array.isArray(json['restrictions']) && json['restrictions'].includes(restriction),
    );
    return { areas, actions, restrictions, filtered: json['filtered'], version: json['version'] };
}

/** Rechte der Standard-Rolle *Admin*: alles (Permissions::all in PHP). */
export const ADMIN_PERMISSIONS: Permissions = {
    areas: { ...AREA_MAX_LEVELS },
    actions: PERMISSION_ACTIONS,
    restrictions: [],
    filtered: false,
    version: null,
};

/** Rechte der Standard-Rolle *Benutzer* (DefaultRoles in PHP, per Test gleich). */
export const USER_PERMISSIONS: Permissions = {
    areas: { search: 'read', due: 'read' },
    actions: ['book.move', 'book.status'],
    restrictions: [],
    filtered: false,
    version: null,
};

/** Ohne Rechte vom Server die der Kontoart (Plan Phase 1, Schritt 1.7); Super-Admin null. */
export function fallbackPermissions(role: UserRole): Permissions | null {
    return role === 'admin' ? ADMIN_PERMISSIONS : role === 'user' ? USER_PERMISSIONS : null;
}

/** Rechte einer Sitzung; null unter `service` (Super-Admin, Seiten nach Rolle). */
export function permissionsOf(state: AuthState | null): Permissions | null {
    if (state === null || state.user.role === 'superadmin') {
        return null;
    }
    return state.permissions ?? fallbackPermissions(state.user.role);
}

/** Darf ansehen (`read`) bzw. ändern (`write`). */
export function allows(
    permissions: Permissions | null,
    area: PermissionArea,
    level: PermissionLevel = 'read',
): boolean {
    const granted = permissions?.areas[area];
    return granted !== undefined && (level === 'read' || granted === 'write');
}

export function can(permissions: Permissions | null, action: PermissionAction): boolean {
    return permissions?.actions.includes(action) === true;
}

/** Gilt die Einschränkung für die Rolle (Phase 2)? */
export function restricted(
    permissions: Permissions | null,
    restriction: PermissionRestriction,
): boolean {
    return permissions?.restrictions.includes(restriction) === true;
}
