import { apiRequest, isRecord, type ApiResult } from './client';
import { isCount, isId, isUtcTime } from './devices';

/** Stufe eines Bereichs (AreaLevel); „Kein Zugriff“ = kein Eintrag. */
export type AreaLevel = 'read' | 'write';

export function isAreaLevel(value: unknown): value is AreaLevel {
    return value === 'read' || value === 'write';
}

/** Bereich des Rechte-Katalogs (RoleJson::catalog). */
export interface CatalogArea {
    readonly key: string;
    readonly label: string;
    readonly hint: string;
    /** Mögliche Stufen, z. B. ['read'] oder ['read', 'write']. */
    readonly levels: readonly AreaLevel[];
    /** Stufen, die es mit „Sieht nur …“ gibt (F3). */
    readonly levelsWithFilter: readonly AreaLevel[];
}

export interface CatalogAction {
    readonly key: string;
    readonly label: string;
    /** Bereich, ohne den die Aktion nicht wirkt; null = keiner. */
    readonly requires: { readonly area: string; readonly level: AreaLevel } | null;
    /** Gibt es die Aktion mit „Sieht nur …“ (F3)? */
    readonly withFilter: boolean;
}

export interface CatalogActionGroup {
    readonly label: string;
    readonly actions: readonly CatalogAction[];
}

/** Einschränkung des Katalogs (Phase 2, „3 · Einschränkungen“). */
export interface CatalogRestriction {
    readonly key: string;
    readonly label: string;
    readonly hint: string;
}

/** GET permission-catalog: Bereiche, Stufen und Aktionen mit deutschen Bezeichnungen. */
export interface PermissionCatalog {
    readonly areas: readonly CatalogArea[];
    readonly restrictions: readonly CatalogRestriction[];
    readonly levelLabels: Readonly<Record<AreaLevel, string>>;
    readonly actionGroups: readonly CatalogActionGroup[];
}

/** Rolle einer Stadt (RoleJson::role). */
export interface Role {
    readonly id: number;
    readonly name: string;
    readonly description: string | null;
    readonly systemKey: 'admin' | 'user' | null;
    /** Rolle Admin: alle Rechte, auch künftige. */
    readonly allRights: boolean;
    readonly editable: boolean;
    readonly deletable: boolean;
    /** Bereich → Stufe, Reihenfolge des Katalogs. */
    readonly areas: Readonly<Record<string, AreaLevel>>;
    readonly actions: readonly string[];
    /** Datenfilter „Sieht nur …“: null = alle. */
    readonly locations: readonly number[] | null;
    readonly deviceTypes: readonly number[] | null;
    /** Einschränkungen (Phase 2), z. B. `single_use_used_only`. */
    readonly restrictions: readonly string[];
    readonly version: number;
    readonly updatedAt: string;
    /** Konten (ohne gelöschte) und offene Einladungscodes. */
    readonly accounts: number;
    readonly invitationCodes: number;
}

/** Ganzer Stand einer Rolle beim Anlegen und Speichern. */
export interface RoleRequest {
    readonly name: string;
    readonly description: string | null;
    readonly areas: Readonly<Record<string, AreaLevel>>;
    readonly actions: readonly string[];
    readonly locations: readonly number[] | null;
    readonly deviceTypes: readonly number[] | null;
    readonly restrictions: readonly string[];
}

/** Standort für die Auswahl „Sieht nur …“ (role-filter-options). */
export interface FilterLocation {
    readonly id: number;
    readonly name: string;
    readonly kindLabel: string;
    readonly active: boolean;
    readonly children: readonly FilterLocation[];
}

export interface FilterOptions {
    readonly locations: readonly FilterLocation[];
    readonly deviceTypes: readonly {
        readonly id: number;
        readonly name: string;
        readonly active: boolean;
    }[];
}

function stringList(value: unknown): string[] | null {
    return Array.isArray(value) && value.every((entry) => typeof entry === 'string') ? value : null;
}

function levelList(value: unknown): AreaLevel[] | null {
    return Array.isArray(value) && value.every(isAreaLevel) ? value : null;
}

function idList(value: unknown): number[] | null | undefined {
    if (value === null) {
        return null;
    }
    return Array.isArray(value) && value.every(isId) ? value : undefined;
}

export function parseCatalog(json: unknown): PermissionCatalog | null {
    if (
        !isRecord(json) ||
        !Array.isArray(json['areas']) ||
        !Array.isArray(json['levels']) ||
        !Array.isArray(json['action_groups'])
    ) {
        return null;
    }
    const areas: CatalogArea[] = [];
    for (const entry of json['areas']) {
        if (!isRecord(entry)) {
            return null;
        }
        const { key, label, hint } = entry;
        const levels = levelList(entry['levels']);
        const levelsWithFilter = levelList(entry['levels_with_filter']);
        if (
            typeof key !== 'string' ||
            typeof label !== 'string' ||
            typeof hint !== 'string' ||
            levels === null ||
            levelsWithFilter === null
        ) {
            return null;
        }
        areas.push({ key, label, hint, levels, levelsWithFilter });
    }
    const levelLabels: Partial<Record<AreaLevel, string>> = {};
    for (const entry of json['levels']) {
        if (!isRecord(entry) || !isAreaLevel(entry['key']) || typeof entry['label'] !== 'string') {
            return null;
        }
        levelLabels[entry['key']] = entry['label'];
    }
    if (levelLabels.read === undefined || levelLabels.write === undefined) {
        return null;
    }
    const actionGroups: CatalogActionGroup[] = [];
    for (const group of json['action_groups']) {
        if (
            !isRecord(group) ||
            typeof group['label'] !== 'string' ||
            !Array.isArray(group['actions'])
        ) {
            return null;
        }
        const actions: CatalogAction[] = [];
        for (const action of group['actions']) {
            if (!isRecord(action)) {
                return null;
            }
            const { key, label, requires } = action;
            const withFilter = action['with_filter'];
            if (
                typeof key !== 'string' ||
                typeof label !== 'string' ||
                typeof withFilter !== 'boolean'
            ) {
                return null;
            }
            if (requires === null) {
                actions.push({ key, label, requires: null, withFilter });
                continue;
            }
            if (
                !isRecord(requires) ||
                typeof requires['area'] !== 'string' ||
                !isAreaLevel(requires['level'])
            ) {
                return null;
            }
            actions.push({
                key,
                label,
                requires: { area: requires['area'], level: requires['level'] },
                withFilter,
            });
        }
        actionGroups.push({ label: group['label'], actions });
    }
    const restrictions: CatalogRestriction[] = [];
    // Fehlt das Feld (Server vor Phase 2), gibt es keine Einschränkungen.
    for (const entry of Array.isArray(json['restrictions'])
        ? (json['restrictions'] as unknown[])
        : []) {
        if (
            !isRecord(entry) ||
            typeof entry['key'] !== 'string' ||
            typeof entry['label'] !== 'string' ||
            typeof entry['hint'] !== 'string'
        ) {
            return null;
        }
        restrictions.push({ key: entry['key'], label: entry['label'], hint: entry['hint'] });
    }
    return {
        areas,
        restrictions,
        levelLabels: { read: levelLabels.read, write: levelLabels.write },
        actionGroups,
    };
}

export function parseRole(json: unknown): Role | null {
    if (!isRecord(json) || !isRecord(json['areas']) || !isRecord(json['usage'])) {
        return null;
    }
    const { id, name, description, editable, deletable, version } = json;
    const systemKey = json['system_key'];
    const allRights = json['all_rights'];
    const updatedAt = json['updated_at'];
    const actions = stringList(json['actions']);
    const locations = idList(json['locations']);
    const deviceTypes = idList(json['device_types']);
    // Fehlt das Feld (Server vor Phase 2): keine Einschränkungen.
    const restrictions = json['restrictions'] === undefined ? [] : stringList(json['restrictions']);
    const accounts = json['usage']['accounts'];
    const invitationCodes = json['usage']['invitation_codes'];
    const areas: Record<string, AreaLevel> = {};
    for (const [key, level] of Object.entries(json['areas'])) {
        if (!isAreaLevel(level)) {
            return null;
        }
        areas[key] = level;
    }
    if (
        !isId(id) ||
        typeof name !== 'string' ||
        (description !== null && typeof description !== 'string') ||
        (systemKey !== null && systemKey !== 'admin' && systemKey !== 'user') ||
        typeof allRights !== 'boolean' ||
        typeof editable !== 'boolean' ||
        typeof deletable !== 'boolean' ||
        actions === null ||
        locations === undefined ||
        deviceTypes === undefined ||
        restrictions === null ||
        !isId(version) ||
        !isUtcTime(updatedAt) ||
        !isCount(accounts) ||
        !isCount(invitationCodes)
    ) {
        return null;
    }
    return {
        id,
        name,
        description,
        systemKey,
        allRights,
        editable,
        deletable,
        areas,
        actions,
        locations,
        deviceTypes,
        restrictions,
        version,
        updatedAt,
        accounts,
        invitationCodes,
    };
}

export function parseRoleList(json: unknown): Role[] | null {
    if (!isRecord(json) || !Array.isArray(json['roles'])) {
        return null;
    }
    const roles: Role[] = [];
    for (const entry of json['roles']) {
        const role = parseRole(entry);
        if (role === null) {
            return null;
        }
        roles.push(role);
    }
    return roles;
}

/** Antwort {"role": …}; auch details.role eines version_conflict. */
export function parseRoleResponse(json: unknown): Role | null {
    return isRecord(json) ? parseRole(json['role']) : null;
}

function parseFilterLocation(json: unknown, level: 1 | 2): FilterLocation | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, name, active } = json;
    const kindLabel = json['kind_label'];
    if (
        !isId(id) ||
        typeof name !== 'string' ||
        typeof kindLabel !== 'string' ||
        typeof active !== 'boolean'
    ) {
        return null;
    }
    const children: FilterLocation[] = [];
    if (level === 1) {
        if (!Array.isArray(json['children'])) {
            return null;
        }
        for (const entry of json['children']) {
            const child = parseFilterLocation(entry, 2);
            if (child === null) {
                return null;
            }
            children.push(child);
        }
    }
    return { id, name, kindLabel, active, children };
}

export function parseFilterOptions(json: unknown): FilterOptions | null {
    if (
        !isRecord(json) ||
        !Array.isArray(json['locations']) ||
        !Array.isArray(json['device_types'])
    ) {
        return null;
    }
    const locations: FilterLocation[] = [];
    for (const entry of json['locations']) {
        const location = parseFilterLocation(entry, 1);
        if (location === null) {
            return null;
        }
        locations.push(location);
    }
    const deviceTypes: { id: number; name: string; active: boolean }[] = [];
    for (const entry of json['device_types']) {
        if (
            !isRecord(entry) ||
            !isId(entry['id']) ||
            typeof entry['name'] !== 'string' ||
            typeof entry['active'] !== 'boolean'
        ) {
            return null;
        }
        deviceTypes.push({ id: entry['id'], name: entry['name'], active: entry['active'] });
    }
    return { locations, deviceTypes };
}

function body(request: RoleRequest): Record<string, unknown> {
    return {
        name: request.name,
        description: request.description,
        areas: request.areas,
        actions: request.actions,
        locations: request.locations,
        device_types: request.deviceTypes,
        restrictions: request.restrictions,
    };
}

function rolesPath(tenantId: number, rest = ''): string {
    return `tenants/${String(tenantId)}/roles${rest}`;
}

/** GET permission-catalog (Super-Admin). */
export function fetchPermissionCatalog(
    fetchFn?: typeof fetch,
): Promise<ApiResult<PermissionCatalog>> {
    return apiRequest('permission-catalog', parseCatalog, { ...(fetchFn && { fetchFn }) });
}

/** GET tenants/{id}/roles: Admin, Benutzer, dann nach Name. */
export function fetchTenantRoles(
    tenantId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role[]>> {
    return apiRequest(rolesPath(tenantId), parseRoleList, { ...(fetchFn && { fetchFn }) });
}

/** GET tenants/{id}/roles/{role}; unbekannt 404. */
export function fetchTenantRole(
    tenantId: number,
    roleId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role>> {
    return apiRequest(rolesPath(tenantId, `/${String(roleId)}`), parseRoleResponse, {
        ...(fetchFn && { fetchFn }),
    });
}

/** GET tenants/{id}/role-filter-options: Standorte und Ausrüstungstypen für „Sieht nur …“. */
export function fetchRoleFilterOptions(
    tenantId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<FilterOptions>> {
    return apiRequest(`tenants/${String(tenantId)}/role-filter-options`, parseFilterOptions, {
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/roles mit ganzem Stand → 201. */
export function postRole(
    tenantId: number,
    request: RoleRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role>> {
    return apiRequest(rolesPath(tenantId), parseRoleResponse, {
        method: 'POST',
        body: body(request),
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/roles {copy_of}: Kopie in derselben Stadt („<Name> (Kopie)“). */
export function postRoleCopy(
    tenantId: number,
    roleId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role>> {
    return apiRequest(rolesPath(tenantId), parseRoleResponse, {
        method: 'POST',
        body: { copy_of: roleId },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/roles/import {source_role_id, name?}: Rechte einer Rolle aus einer anderen Stadt. */
export function postRoleImport(
    tenantId: number,
    sourceRoleId: number,
    name: string | null,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role>> {
    return apiRequest(rolesPath(tenantId, '/import'), parseRoleResponse, {
        method: 'POST',
        body:
            name === null
                ? { source_role_id: sourceRoleId }
                : { source_role_id: sourceRoleId, name },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/roles/{role} {version, ganzer Stand}; veraltet 409 version_conflict. */
export function postRoleSave(
    tenantId: number,
    roleId: number,
    version: number,
    request: RoleRequest,
    fetchFn?: typeof fetch,
): Promise<ApiResult<Role>> {
    return apiRequest(rolesPath(tenantId, `/${String(roleId)}`), parseRoleResponse, {
        method: 'POST',
        body: { version, ...body(request) },
        ...(fetchFn && { fetchFn }),
    });
}

/** DELETE tenants/{id}/roles/{role} {}: nur ohne Konten und offene Einladungscodes. */
export function deleteRole(
    tenantId: number,
    roleId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<number>> {
    return apiRequest(
        rolesPath(tenantId, `/${String(roleId)}`),
        (json) =>
            isRecord(json) && isId(json['deleted_role_id']) ? json['deleted_role_id'] : null,
        { method: 'DELETE', ...(fetchFn && { fetchFn }) },
    );
}

/** Rolle der eigenen Stadt in der Auswahl des Admins (GET roles, Plan Phase 1, Schritt 1.6). */
export interface AssignableRole extends Role {
    /** Darf das Konto der Sitzung die Rolle vergeben (F5)? */
    readonly assignable: boolean;
    /** Warum nicht (Meldung des Servers); null = darf. */
    readonly assignBlocker: string | null;
}

export interface AssignableRoles {
    readonly roles: readonly AssignableRole[];
    readonly catalog: PermissionCatalog;
}

export function parseAssignableRoles(json: unknown): AssignableRoles | null {
    if (!isRecord(json) || !Array.isArray(json['roles'])) {
        return null;
    }
    const catalog = parseCatalog(json['catalog']);
    if (catalog === null) {
        return null;
    }
    const roles: AssignableRole[] = [];
    for (const entry of json['roles']) {
        const role = parseRole(entry);
        if (role === null || !isRecord(entry)) {
            return null;
        }
        const assignable = entry['assignable'];
        const assignBlocker = entry['assign_blocker'];
        if (
            typeof assignable !== 'boolean' ||
            (assignBlocker !== null && typeof assignBlocker !== 'string') ||
            assignable !== (assignBlocker === null)
        ) {
            return null;
        }
        roles.push({ ...role, assignable, assignBlocker });
    }
    return { roles, catalog };
}

/** GET roles (Benutzer: Ansehen): Rollen der eigenen Stadt zur Auswahl, mit Katalog. */
export function fetchAssignableRoles(fetchFn?: typeof fetch): Promise<ApiResult<AssignableRoles>> {
    return apiRequest('roles', parseAssignableRoles, { ...(fetchFn && { fetchFn }) });
}
