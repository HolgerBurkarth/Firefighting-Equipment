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
    readonly role: UserRole;
    readonly roleLabel: string;
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
        status: status as AccountStatus,
        statusLabel,
        locked,
        emailVerifiedAt,
        createdAt,
    };
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
