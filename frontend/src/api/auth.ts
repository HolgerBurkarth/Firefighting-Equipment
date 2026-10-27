import { apiRequest, isRecord, type ApiResult } from './client';

/** Rollen wie UserRole in PHP. */
export type UserRole = 'user' | 'admin' | 'superadmin';

const ROLES: readonly UserRole[] = ['user', 'admin', 'superadmin'];

export interface SessionUser {
    readonly id: number;
    readonly email: string | null;
    readonly name: string | null;
    readonly displayName: string | null;
    readonly role: UserRole;
}

/** Antwort von auth/login und auth/me (AuthJson::me), auf das Nötige beschränkt. */
export interface AuthState {
    readonly user: SessionUser;
    /** Stadt des Kontos; null beim Super-Admin. */
    readonly tenant: { readonly slug: string; readonly name: string } | null;
}

function optionalString(value: unknown): string | null | undefined {
    if (value === null) {
        return null;
    }
    return typeof value === 'string' ? value : undefined;
}

function isRole(value: unknown): value is UserRole {
    return typeof value === 'string' && (ROLES as readonly string[]).includes(value);
}

export function parseAuthState(json: unknown): AuthState | null {
    if (!isRecord(json) || !isRecord(json['user'])) {
        return null;
    }
    const user = json['user'];
    const id = user['id'];
    const email = optionalString(user['email']);
    const name = optionalString(user['name']);
    const displayName = optionalString(user['display_name']);
    const role = user['role'];
    if (
        typeof id !== 'number' ||
        !Number.isInteger(id) ||
        email === undefined ||
        name === undefined ||
        displayName === undefined ||
        !isRole(role)
    ) {
        return null;
    }

    const tenantJson = json['tenant'];
    let tenant: AuthState['tenant'] = null;
    if (tenantJson !== null) {
        if (
            !isRecord(tenantJson) ||
            typeof tenantJson['slug'] !== 'string' ||
            typeof tenantJson['name'] !== 'string'
        ) {
            return null;
        }
        tenant = { slug: tenantJson['slug'], name: tenantJson['name'] };
    }
    return { user: { id, email, name, displayName, role }, tenant };
}

/** Antworten mit einer Meldung des Servers (202 bei Mail-Abläufen, 200 bei Abmelden u. a.). */
export function parseMessage(json: unknown): { readonly message: string } | null {
    return isRecord(json) && typeof json['message'] === 'string'
        ? { message: json['message'] }
        : null;
}

function parseAny(): Record<string, never> {
    return {};
}

/** GET auth/me; 401 behandelt der Aufrufer (Router-Wächter), nicht der allgemeine Abmelde-Weg. */
export function fetchMe(fetchFn?: typeof fetch): Promise<ApiResult<AuthState>> {
    return apiRequest('auth/me', parseAuthState, { public: true, ...(fetchFn && { fetchFn }) });
}

export function login(
    email: string,
    password: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AuthState>> {
    return apiRequest('auth/login', parseAuthState, {
        method: 'POST',
        body: { email, password },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST auth/logout (wiederholbar, löscht das Cookie immer). */
export function logout(fetchFn?: typeof fetch): Promise<ApiResult<Record<string, never>>> {
    return apiRequest('auth/logout', parseAny, {
        method: 'POST',
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** Anzeigename, sonst Name, sonst E-Mail. */
export function userLabel(user: SessionUser): string {
    return user.displayName ?? user.name ?? user.email ?? `Konto #${user.id}`;
}

export const ROLE_LABELS: Readonly<Record<UserRole, string>> = {
    user: 'Benutzer',
    admin: 'Admin',
    superadmin: 'Super-Admin',
};

/** POST auth/email/resend: immer 202 mit derselben Meldung (nur unter Städten). */
export function resendVerification(
    email: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/email/resend', parseMessage, {
        method: 'POST',
        body: { email },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}
