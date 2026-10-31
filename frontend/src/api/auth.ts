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

/**
 * POST auth/password: eigenes Passwort ändern. Antwort wie auth/me mit der neuen Sitzung (der Server
 * ersetzt das Cookie und beendet alle anderen Sitzungen). Ein falsches aktuelles Passwort ist 403
 * `invalid_current_password` (nicht 401, sonst würde abgemeldet) und zählt in die Bremse (429).
 */
export function changePassword(
    currentPassword: string,
    newPassword: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AuthState>> {
    return apiRequest('auth/password', parseAuthState, {
        method: 'POST',
        body: { current_password: currentPassword, new_password: newPassword },
        ...(fetchFn && { fetchFn }),
    });
}

/** Benachrichtigungen des eigenen Kontos (NotificationSettingsHandler::json). */
export interface NotificationSettings {
    /** Mail „Log“ bei jedem Vorgang der Geräte-Historie (eigene ausgenommen). */
    readonly deviceLog: boolean;
}

export function parseNotifications(json: unknown): NotificationSettings | null {
    if (!isRecord(json) || !isRecord(json['notifications'])) {
        return null;
    }
    const deviceLog = json['notifications']['device_log'];
    return typeof deviceLog === 'boolean' ? { deviceLog } : null;
}

/** GET auth/notifications (Admins unter ihrer Stadt, Super-Admins unter service). */
export function fetchNotifications(
    fetchFn?: typeof fetch,
): Promise<ApiResult<NotificationSettings>> {
    return apiRequest('auth/notifications', parseNotifications, { ...(fetchFn && { fetchFn }) });
}

/** POST auth/notifications {device_log} (wiederholbar); Antwort mit dem Stand danach. */
export function saveNotifications(
    deviceLog: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<NotificationSettings>> {
    return apiRequest('auth/notifications', parseNotifications, {
        method: 'POST',
        body: { device_log: deviceLog },
        ...(fetchFn && { fetchFn }),
    });
}

/** Sitzung des eigenen Kontos wie AuthJson::session (Zeiten in UTC, `…Z`). */
export interface AccountSession {
    readonly id: number;
    /** Grobe Gerätebezeichnung, z. B. „Chrome auf Android“; null = unbekannt. */
    readonly deviceLabel: string | null;
    readonly createdAt: string;
    readonly lastSeenAt: string;
    readonly current: boolean;
}

const UTC_TIME = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

function parseSession(json: unknown): AccountSession | null {
    if (!isRecord(json)) {
        return null;
    }
    const { id, device_label: label, created_at: created, last_seen_at: seen, current } = json;
    if (
        typeof id !== 'number' ||
        !Number.isInteger(id) ||
        (label !== null && typeof label !== 'string') ||
        typeof created !== 'string' ||
        !UTC_TIME.test(created) ||
        typeof seen !== 'string' ||
        !UTC_TIME.test(seen) ||
        typeof current !== 'boolean'
    ) {
        return null;
    }
    return { id, deviceLabel: label, createdAt: created, lastSeenAt: seen, current };
}

export function parseSessions(json: unknown): readonly AccountSession[] | null {
    if (!isRecord(json) || !Array.isArray(json['sessions'])) {
        return null;
    }
    const sessions = json['sessions'].map(parseSession);
    return sessions.every((session) => session !== null) ? sessions : null;
}

/** GET auth/sessions: gültige Sitzungen des eigenen Kontos, zuletzt benutzte zuerst. */
export function fetchSessions(
    fetchFn?: typeof fetch,
): Promise<ApiResult<readonly AccountSession[]>> {
    return apiRequest('auth/sessions', parseSessions, { ...(fetchFn && { fetchFn }) });
}

/** DELETE auth/sessions/{id}: eine eigene Sitzung beenden (fremd oder schon beendet: 404). */
export function endSession(
    id: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly current: boolean }>> {
    return apiRequest(
        `auth/sessions/${String(id)}`,
        (json) =>
            isRecord(json) && typeof json['current'] === 'boolean'
                ? { current: json['current'] }
                : null,
        { method: 'DELETE', ...(fetchFn && { fetchFn }) },
    );
}

/** POST auth/sessions/logout-others: alle anderen Sitzungen beenden; liefert deren Anzahl. */
export function logoutOtherSessions(
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly endedSessions: number }>> {
    return apiRequest(
        'auth/sessions/logout-others',
        (json) =>
            isRecord(json) &&
            typeof json['ended_sessions'] === 'number' &&
            Number.isInteger(json['ended_sessions'])
                ? { endedSessions: json['ended_sessions'] }
                : null,
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}

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

/** POST auth/password-reset/request: immer 202 mit derselben Meldung (Stadt und service). */
export function requestPasswordReset(
    email: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/password-reset/request', parseMessage, {
        method: 'POST',
        body: { email },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST auth/password-reset/confirm mit dem Token aus dem Mail-Link (nie in der Adresse). */
export function confirmPasswordReset(
    token: string,
    newPassword: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/password-reset/confirm', parseMessage, {
        method: 'POST',
        body: { token, new_password: newPassword },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST auth/register (nur unter Städten): 202 mit derselben Meldung, ob neu oder schon vorhanden. */
export function register(
    input: {
        readonly code: string;
        readonly email: string;
        readonly password: string;
        readonly name: string;
    },
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/register', parseMessage, {
        method: 'POST',
        body: input,
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST auth/email/verify mit dem Token aus dem Mail-Link (nie in der Adresse). */
export function verifyEmail(
    token: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/email/verify', parseMessage, {
        method: 'POST',
        body: { token },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}

/** POST auth/invitation/accept (nur unter Städten): Token aus dem Mail-Link, Name, Passwort. */
export function acceptInvitation(
    token: string,
    name: string,
    password: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<{ readonly message: string }>> {
    return apiRequest('auth/invitation/accept', parseMessage, {
        method: 'POST',
        body: { token, name, password },
        public: true,
        ...(fetchFn && { fetchFn }),
    });
}
