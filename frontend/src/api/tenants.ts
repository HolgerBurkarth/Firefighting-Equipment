import { apiRequest, isRecord, type ApiResult } from './client';
import { isCount, isId, isUtcTime } from './devices';
import { parseAdminUser, parseUserList, type AccountStatus, type AdminUser } from './users';

/** Stadt in der Verwaltung des Super-Admins (TenantJson::tenant). */
export interface ManagedTenant {
    readonly id: number;
    readonly slug: string;
    readonly name: string;
    readonly active: boolean;
    readonly createdAt: string;
    readonly updatedAt: string;
    /** Aktive, nicht gesperrte Konten (alle Rollen). */
    readonly usersActive: number;
    readonly adminsActive: number;
    readonly adminsInvited: number;
}

export function parseManagedTenant(json: unknown): ManagedTenant | null {
    if (!isRecord(json) || !isRecord(json['counts'])) {
        return null;
    }
    const { id, slug, name, active } = json;
    const createdAt = json['created_at'];
    const updatedAt = json['updated_at'];
    const counts = json['counts'];
    const usersActive = counts['users_active'];
    const adminsActive = counts['admins_active'];
    const adminsInvited = counts['admins_invited'];
    if (
        !isId(id) ||
        typeof slug !== 'string' ||
        typeof name !== 'string' ||
        typeof active !== 'boolean' ||
        !isUtcTime(createdAt) ||
        !isUtcTime(updatedAt) ||
        !isCount(usersActive) ||
        !isCount(adminsActive) ||
        !isCount(adminsInvited)
    ) {
        return null;
    }
    return {
        id,
        slug,
        name,
        active,
        createdAt,
        updatedAt,
        usersActive,
        adminsActive,
        adminsInvited,
    };
}

export function parseTenantList(json: unknown): ManagedTenant[] | null {
    if (!isRecord(json) || !Array.isArray(json['tenants'])) {
        return null;
    }
    const tenants: ManagedTenant[] = [];
    for (const entry of json['tenants']) {
        const tenant = parseManagedTenant(entry);
        if (tenant === null) {
            return null;
        }
        tenants.push(tenant);
    }
    return tenants;
}

function parseTenantResponse(json: unknown): ManagedTenant | null {
    return isRecord(json) ? parseManagedTenant(json['tenant']) : null;
}

/** GET tenants (Super-Admin): alle Städte nach Kürzel, mit Zählern. */
export function fetchTenants(fetchFn?: typeof fetch): Promise<ApiResult<ManagedTenant[]>> {
    return apiRequest('tenants', parseTenantList, { ...(fetchFn && { fetchFn }) });
}

/** GET tenants/{id}; unbekannt 404. */
export function fetchTenant(id: number, fetchFn?: typeof fetch): Promise<ApiResult<ManagedTenant>> {
    return apiRequest(`tenants/${String(id)}`, parseTenantResponse, {
        ...(fetchFn && { fetchFn }),
    });
}

/** GET tenants/{id}/users[?status=…]: Konten einer Stadt, nur lesend (ohne Filter ohne gelöschte). */
export function fetchTenantUsers(
    id: number,
    status?: AccountStatus,
    fetchFn?: typeof fetch,
): Promise<ApiResult<AdminUser[]>> {
    const query = status === undefined ? '' : `?${new URLSearchParams({ status }).toString()}`;
    return apiRequest(`tenants/${String(id)}/users${query}`, parseUserList, {
        ...(fetchFn && { fetchFn }),
    });
}

/** Antwort von GET tenants/slug-available (TenantSlugAvailabilityHandler). */
export interface SlugAvailability {
    readonly slug: string;
    readonly valid: boolean;
    readonly available: boolean;
    readonly message: string;
    readonly suggestion: string | null;
}

export function parseSlugAvailability(json: unknown): SlugAvailability | null {
    if (!isRecord(json)) {
        return null;
    }
    const { slug, valid, available, message, suggestion } = json;
    if (
        typeof slug !== 'string' ||
        typeof valid !== 'boolean' ||
        typeof available !== 'boolean' ||
        typeof message !== 'string' ||
        (suggestion !== null && typeof suggestion !== 'string')
    ) {
        return null;
    }
    return { slug, valid, available, message, suggestion };
}

/** GET tenants/slug-available?slug=…: Vorabprüfung, verbindlich bleibt POST tenants. */
export function checkSlug(
    slug: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<SlugAvailability>> {
    const query = new URLSearchParams({ slug }).toString();
    return apiRequest(`tenants/slug-available?${query}`, parseSlugAvailability, {
        ...(fetchFn && { fetchFn }),
    });
}

/** Antwort mit Konto und Ergebnis des Mailversands (Einladung eines Admins). */
export interface InvitationResult {
    readonly user: AdminUser;
    /** false: Versand gescheitert, „Einladung erneut senden“. */
    readonly mailSent: boolean;
}

export interface CreatedTenant extends InvitationResult {
    readonly tenant: ManagedTenant;
}

function parseInvitationResult(json: unknown): InvitationResult | null {
    if (!isRecord(json) || typeof json['mail_sent'] !== 'boolean') {
        return null;
    }
    const user = parseAdminUser(json['user']);
    return user === null ? null : { user, mailSent: json['mail_sent'] };
}

function parseCreatedTenant(json: unknown): CreatedTenant | null {
    const invitation = parseInvitationResult(json);
    const tenant = parseTenantResponse(json);
    return invitation === null || tenant === null ? null : { ...invitation, tenant };
}

/** POST tenants {slug, name, admin_email} → 201: Stadt und eingeladener erster Admin. */
export function createTenant(
    request: { readonly slug: string; readonly name: string; readonly adminEmail: string },
    fetchFn?: typeof fetch,
): Promise<ApiResult<CreatedTenant>> {
    return apiRequest('tenants', parseCreatedTenant, {
        method: 'POST',
        body: { slug: request.slug, name: request.name, admin_email: request.adminEmail },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/rename {name}. */
export function renameTenant(
    id: number,
    name: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<ManagedTenant>> {
    return apiRequest(`tenants/${String(id)}/rename`, parseTenantResponse, {
        method: 'POST',
        body: { name },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/lock bzw. unlock (wiederholbar; Sperren beendet alle Sitzungen der Stadt). */
export function setTenantLocked(
    id: number,
    locked: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<ManagedTenant>> {
    return apiRequest(`tenants/${String(id)}/${locked ? 'lock' : 'unlock'}`, parseTenantResponse, {
        method: 'POST',
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/admins {email} → 201: weiteren Admin einladen. */
export function inviteAdmin(
    id: number,
    email: string,
    fetchFn?: typeof fetch,
): Promise<ApiResult<InvitationResult>> {
    return apiRequest(`tenants/${String(id)}/admins`, parseInvitationResult, {
        method: 'POST',
        body: { email },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenants/{id}/users/{user}/resend-invitation: neuer Link, der alte gilt nicht mehr. */
export function resendInvitation(
    tenantId: number,
    userId: number,
    fetchFn?: typeof fetch,
): Promise<ApiResult<InvitationResult>> {
    return apiRequest(
        `tenants/${String(tenantId)}/users/${String(userId)}/resend-invitation`,
        parseInvitationResult,
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}

/** Super-Admin (SuperAdminJson: UserJson plus `protected`). */
export interface SuperAdmin extends AdminUser {
    /** Geschütztes Konto: nur per Kommandozeile änderbar, ohne Knopf. */
    readonly protected: boolean;
}

export function parseSuperAdmin(json: unknown): SuperAdmin | null {
    const user = parseAdminUser(json);
    if (user === null || !isRecord(json) || typeof json['protected'] !== 'boolean') {
        return null;
    }
    return { ...user, protected: json['protected'] };
}

export function parseSuperAdminList(json: unknown): SuperAdmin[] | null {
    if (!isRecord(json) || !Array.isArray(json['super_admins'])) {
        return null;
    }
    const admins: SuperAdmin[] = [];
    for (const entry of json['super_admins']) {
        const admin = parseSuperAdmin(entry);
        if (admin === null) {
            return null;
        }
        admins.push(admin);
    }
    return admins;
}

/** GET superadmins: alle Super-Admins nach ID. */
export function fetchSuperAdmins(fetchFn?: typeof fetch): Promise<ApiResult<SuperAdmin[]>> {
    return apiRequest('superadmins', parseSuperAdminList, { ...(fetchFn && { fetchFn }) });
}

/** POST superadmins/{id}/lock bzw. unlock (wiederholbar; Sperren beendet die Sitzungen). */
export function setSuperAdminLocked(
    id: number,
    locked: boolean,
    fetchFn?: typeof fetch,
): Promise<ApiResult<SuperAdmin>> {
    return apiRequest(
        `superadmins/${String(id)}/${locked ? 'lock' : 'unlock'}`,
        (json) => (isRecord(json) ? parseSuperAdmin(json['super_admin']) : null),
        { method: 'POST', ...(fetchFn && { fetchFn }) },
    );
}
