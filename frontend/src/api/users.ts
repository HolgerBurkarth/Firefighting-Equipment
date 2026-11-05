import type { UserRole } from './auth';
import { apiRequest, isRecord, type ApiResult } from './client';
import { isId, isUtcTime, nullableString } from './devices';

/** Status eines Kontos wie UserStatus in PHP. */
export type AccountStatus = 'invited' | 'unverified' | 'pending' | 'active' | 'deleted';

const STATUSES: readonly AccountStatus[] = [
    'invited',
    'unverified',
    'pending',
    'active',
    'deleted',
];
const ROLES: readonly UserRole[] = ['user', 'admin', 'superadmin'];

/** Konto der Benutzerverwaltung (UserJson::user, nie mit Passwort-Hash). */
export interface AdminUser {
    readonly id: number;
    /** Anzeigename, sonst Name bzw. E-Mail; anonymisiert „Gelöschter Benutzer #ID“. */
    readonly label: string;
    readonly email: string | null;
    readonly name: string | null;
    readonly displayName: string | null;
    /** Kontoart (superadmin, admin, user); `admin` = hat die Rolle Admin. */
    readonly role: UserRole;
    readonly roleLabel: string;
    /** Rolle der Stadt (Plan Phase 1, Schritt 1.6); null bei Super-Admins. */
    readonly roleId: number | null;
    readonly roleName: string | null;
    /** Einladungscode der Registrierung (Bezeichnung des Aushangs), sonst null. */
    readonly invitationCode: { readonly id: number; readonly label: string | null } | null;
    readonly status: AccountStatus;
    readonly statusLabel: string;
    readonly locked: boolean;
    readonly emailVerifiedAt: string | null;
    readonly createdAt: string;
}

export function parseAdminUser(json: unknown): AdminUser | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, label, role, status, locked } = json;
    const email = nullableString(json['email']);
    const name = nullableString(json['name']);
    const displayName = nullableString(json['display_name']);
    const roleLabel = json['role_label'];
    const statusLabel = json['status_label'];
    const emailVerifiedAt = json['email_verified_at'];
    const createdAt = json['created_at'];
    const roleId = json['role_id'] ?? null;
    const roleName = nullableString(json['role_name'] ?? null);
    const invitationCode = parseInvitationRef(json['invitation_code'] ?? null);
    if (
        (roleId !== null && !isId(roleId)) ||
        roleName === undefined ||
        invitationCode === undefined
    ) {
        return null;
    }
    if (
        !isId(id) ||
        typeof label !== 'string' ||
        email === undefined ||
        name === undefined ||
        displayName === undefined ||
        typeof role !== 'string' ||
        !(ROLES as readonly string[]).includes(role) ||
        typeof roleLabel !== 'string' ||
        typeof status !== 'string' ||
        !(STATUSES as readonly string[]).includes(status) ||
        typeof statusLabel !== 'string' ||
        typeof locked !== 'boolean' ||
        (emailVerifiedAt !== null && !isUtcTime(emailVerifiedAt)) ||
        !isUtcTime(createdAt)
    ) {
        return null;
    }
    return {
        id,
        label,
        email,
        name,
        displayName,
        role: role as UserRole,
        roleLabel,
        roleId,
        roleName,
        invitationCode,
        status: status as AccountStatus,
        statusLabel,
        locked,
        emailVerifiedAt,
        createdAt,
    };
}

/** `invitation_code` {id, label} oder null; undefined = ungültig. */
function parseInvitationRef(
    json: unknown,
): { readonly id: number; readonly label: string | null } | null | undefined {
    if (json === null) {
        return null;
    }
    if (!isRecord(json) || !isId(json['id'])) {
        return undefined;
    }
    const label = nullableString(json['label']);
    return label === undefined ? undefined : { id: json['id'], label };
}

export function parseUserList(json: unknown): AdminUser[] | null {
    if (!isRecord(json) || !Array.isArray(json['users'])) {
        return null;
    }
    const users: AdminUser[] = [];
    for (const entry of json['users']) {
        const user = parseAdminUser(entry);
        if (user === null) {
            return null;
        }
        users.push(user);
    }
    return users;
}

/** GET users[?status=…] (Admin): Konten der eigenen Stadt, nach ID; ohne Filter ohne gelöschte. */
export function fetchUsers(
    status?: AccountStatus,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser[]>> {
    const query = status === undefined ? '' : `?${new URLSearchParams({ status }).toString()}`;
    return apiRequest(`users${query}`, parseUserList, { ...(fetchFn && { fetchFn }) });
}

function parseUserResponse(json: unknown): AdminUser | null {
    return isRecord(json) ? parseAdminUser(json['user']) : null;
}

function userAction(
    id: number,
    action: string,
    body: Record<string, unknown>,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser>> {
    return apiRequest(`users/${String(id)}/${action}`, parseUserResponse, {
        method: 'POST',
        body,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST users/{id}/approve: Registrierung freischalten (nur `pending`; Mail an das Konto). */
export function approveUser(
    id: number,
    request: { readonly displayName: string; readonly roleId: number },
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser>> {
    return userAction(
        id,
        'approve',
        { display_name: request.displayName, role_id: request.roleId },
        fetchFn,
    );
}

/** POST users/{id}/reject: Registrierung ablehnen (anonymisiert; Mail an die bisherige Adresse). */
export function rejectUser(id: number, fetchFn?: typeof fetch): Promise<ApiResult<AdminUser>> {
    return userAction(id, 'reject', {}, fetchFn);
}

/** POST users/{id}/lock bzw. unlock (Sperren beendet alle Sitzungen des Kontos). */
export function setUserLocked(
    id: number,
    locked: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser>> {
    return userAction(id, locked ? 'lock' : 'unlock', {}, fetchFn);
}

/**
 * POST users/{id}/role {role_id}: Rolle der Stadt zuweisen (beendet die Sitzungen nur, wenn die
 * Rolle Admin dazukommt oder wegfällt, F1).
 */
export function changeUserRole(
    id: number,
    roleId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser>> {
    return userAction(id, 'role', { role_id: roleId }, fetchFn);
}

/** Höchstzahl der Konten je Anfrage (UserBulkRoleHandler::MAX_USERS). */
export const BULK_ROLE_MAX_USERS = 50;

export type BulkRoleOutcome = 'applied' | 'unchanged' | 'error';

/** Ergebnis je Konto von POST users/bulk/role (wie die Sammelaktionen der Geräte). */
export interface BulkRoleResult {
    readonly id: number;
    readonly outcome: BulkRoleOutcome;
    /** Stand danach bzw. bei Fehler der aktuelle; null bei fremder oder unbekannter ID. */
    readonly user: AdminUser | null;
    readonly error: { readonly code: string; readonly message: string } | null;
}

function parseBulkRoleResult(json: unknown): BulkRoleResult | null {
    if (!isRecord(json) || !isId(json['id'])) {
        return null;
    }
    const outcome = json['outcome'];
    const user = json['user'] === null ? null : parseAdminUser(json['user']);
    const rawError = json['error'];
    let error: BulkRoleResult['error'] = null;
    if (rawError !== null) {
        if (
            !isRecord(rawError) ||
            typeof rawError['error'] !== 'string' ||
            typeof rawError['message'] !== 'string'
        ) {
            return null;
        }
        error = { code: rawError['error'], message: rawError['message'] };
    }
    if (
        (outcome !== 'applied' && outcome !== 'unchanged' && outcome !== 'error') ||
        (json['user'] !== null && user === null) ||
        (outcome === 'error') !== (error !== null)
    ) {
        return null;
    }
    return { id: json['id'], outcome, user, error };
}

export function parseBulkRoleResponse(json: unknown): BulkRoleResult[] | null {
    if (!isRecord(json) || !Array.isArray(json['results'])) {
        return null;
    }
    const results: BulkRoleResult[] = [];
    for (const entry of json['results']) {
        const result = parseBulkRoleResult(entry);
        if (result === null) {
            return null;
        }
        results.push(result);
    }
    return results;
}

/** POST users/bulk/role {user_ids, role_id}: höchstens BULK_ROLE_MAX_USERS Konten. */
export function postBulkRole(
    userIds: readonly number[],
    roleId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<BulkRoleResult[]>> {
    return apiRequest('users/bulk/role', parseBulkRoleResponse, {
        method: 'POST',
        body: { user_ids: userIds, role_id: roleId },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST users/{id}/display-name. */
export function changeUserDisplayName(
    id: number,
    displayName: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser>> {
    return userAction(id, 'display-name', { display_name: displayName }, fetchFn);
}

/** DELETE users/{id}: löschen = anonymisieren (der Verlauf behält „Gelöschter Benutzer #ID“). */
export function deleteUser(id: number, fetchFn?: typeof fetch): Promise<ApiResult<AdminUser>> {
    return apiRequest(`users/${String(id)}`, parseUserResponse, {
        method: 'DELETE',
        ...(fetchFn && { fetchFn }),
    });
}
