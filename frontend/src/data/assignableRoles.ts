import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import {
    fetchAssignableRoles,
    type AssignableRole,
    type AssignableRoles,
    type PermissionCatalog,
} from '../api/roles';
import type { AdminUser } from '../api/users';
import { Refresher, type RefreshReason } from './refresher';
import { roleSummary } from './roles';

/**
 * Rollen der eigenen Stadt für die Benutzerverwaltung (GET roles, Plan Phase 1, Schritt 1.6):
 * Auswahl beim Zuweisen, Freischalten und bei Einladungscodes, Rollenfilter der Liste. Welche Rollen
 * es gibt und was sie dürfen, legt der Super-Admin fest; ob das Konto der Sitzung eine vergeben
 * darf, sagt der Server (`assignable`, F5).
 */

export interface AssignableRoleState {
    /** null, solange noch nie geladen. */
    readonly data: AssignableRoles | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; der bisherige Stand bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: AssignableRoleState = { data: null, loading: false, error: null };

export class AssignableRoleStore {
    private readonly current = shallowRef<AssignableRoleState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchRoles: () => Promise<ApiResult<AssignableRoles>> = () =>
            fetchAssignableRoles(),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): AssignableRoleState {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        const result = await this.fetchRoles();
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? { data: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}

/** Name der Rolle eines Kontos (aus der Antwort, sonst die Kontoart). */
export function roleNameOf(user: AdminUser): string {
    return user.roleName ?? user.roleLabel;
}

/** „Sieht: …“ unter einer Rolle in der Auswahl (Mockup „Benutzer-Rolle“). */
export function seesText(catalog: PermissionCatalog, role: AssignableRole): string {
    return roleSummary(catalog, role).sees;
}

/**
 * „Sieht nur …“ mit Namen (Standorte und Ausrüstungstypen aus den Listen der Konsole); null ohne
 * Datenfilter. Unbekannte IDs zählen als „ein weiterer …“, damit nichts still fehlt.
 */
export function filterText(
    role: AssignableRole,
    locationName: (id: number) => string | null,
    typeName: (id: number) => string | null,
): string | null {
    const names = (ids: readonly number[], lookup: (id: number) => string | null): string[] =>
        ids.map((id) => lookup(id) ?? `#${String(id)}`);
    const parts: string[] = [];
    if (role.locations !== null) {
        parts.push(
            role.locations.length === 0
                ? 'kein Standort'
                : names(role.locations, locationName).join(', '),
        );
    }
    if (role.deviceTypes !== null) {
        parts.push(
            role.deviceTypes.length === 0
                ? 'kein Ausrüstungstyp'
                : names(role.deviceTypes, typeName).join(', '),
        );
    }
    return parts.length === 0 ? null : `Sieht nur: ${parts.join('; ')}`;
}

/** Knöpfe „Rolle:“ über der Liste: „Alle (7)“, dann je Rolle mit Konten (Mockup „Benutzer“). */
export interface RoleFilterOption {
    /** null = alle. */
    readonly roleId: number | null;
    readonly label: string;
    readonly count: number;
}

export function roleFilterOptions(
    users: readonly AdminUser[],
    roles: readonly AssignableRole[],
): RoleFilterOption[] {
    const listed = users.filter((user) => user.status !== 'pending');
    const count = (roleId: number): number =>
        listed.filter((user) => user.roleId === roleId).length;
    return [
        { roleId: null, label: 'Alle', count: listed.length },
        ...roles
            .map((role) => ({ roleId: role.id, label: role.name, count: count(role.id) }))
            .filter((option) => option.count > 0),
    ];
}

/** Rollen, die ein Einladungscode haben kann: alle außer Admin (Mockup „Einladungscodes“). */
export function codeRoles(roles: readonly AssignableRole[]): AssignableRole[] {
    return roles.filter((role) => role.systemKey !== 'admin');
}

/** Standard-Rolle Benutzer (vorgewählt bei neuen Codes). */
export function defaultRole(roles: readonly AssignableRole[]): AssignableRole | null {
    return roles.find((role) => role.systemKey === 'user') ?? null;
}
