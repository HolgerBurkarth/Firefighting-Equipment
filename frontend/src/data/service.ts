import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import {
    createTenant as postTenant,
    fetchSuperAdmins,
    fetchTenants,
    inviteAdmin as postInviteAdmin,
    renameTenant as postRenameTenant,
    resendInvitation as postResendInvitation,
    setSuperAdminLocked as postSuperAdminLocked,
    setTenantLocked as postTenantLocked,
    type CreatedTenant,
    type InvitationResult,
    type ManagedTenant,
    type SuperAdmin,
} from '../api/tenants';
import { onSessionEnded } from '../auth/session';
import { Refresher, type RefreshReason } from './refresher';

export interface ListState<T> {
    /** null, solange noch nie geladen. */
    readonly items: readonly T[] | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

/** Eine Liste des Super-Admins (Städte, Super-Admins) mit Auffrischen wie die Stores der Städte. */
export class ListStore<T> {
    private readonly current = shallowRef<ListState<T>>({
        items: null,
        loading: false,
        error: null,
    });
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchList: () => Promise<ApiResult<readonly T[]>>,
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): ListState<T> {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = { items: null, loading: false, error: null };
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        const result = await this.fetchList();
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? { items: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}

/** Alle Städte (GET tenants) für Dashboard, Liste und Detail des Super-Admins. */
export const tenantList = new ListStore<ManagedTenant>(() => fetchTenants());
/** Alle Super-Admins (GET superadmins). */
export const superAdminList = new ListStore<SuperAdmin>(() => fetchSuperAdmins());

const SERVICE_STORES = [tenantList, superAdminList] as const;

/**
 * Datenschicht unter `service` (in main.ts, statt installDataLayer der Städte): Abmelden und eine
 * abgelaufene Sitzung leeren die Listen, sichtbar werden frischt geladene auf.
 */
export function installServiceDataLayer(doc: Document = document): () => void {
    const stopSession = onSessionEnded(() => {
        for (const store of SERVICE_STORES) {
            store.clear();
        }
    });
    const onVisibility = (): void => {
        if (doc.visibilityState === 'visible') {
            for (const store of SERVICE_STORES) {
                void store.refresh('visible');
            }
        }
    };
    doc.addEventListener('visibilitychange', onVisibility);
    return () => {
        stopSession();
        doc.removeEventListener('visibilitychange', onVisibility);
    };
}

/** Nach jeder Änderung an einer Stadt: Liste neu (abgewartet, die Seite zeigt danach den Stand). */
async function afterTenantChange<R>(result: ApiResult<R>): Promise<ApiResult<R>> {
    if (result.ok || result.error.code === 'not_found' || result.error.code === 'rule_violation') {
        await tenantList.refresh('changed');
    }
    return result;
}

export async function createTenant(request: {
    readonly slug: string;
    readonly name: string;
    readonly adminEmail: string;
}): Promise<ApiResult<CreatedTenant>> {
    return afterTenantChange(await postTenant(request));
}

export async function renameTenant(id: number, name: string): Promise<ApiResult<ManagedTenant>> {
    return afterTenantChange(await postRenameTenant(id, name));
}

export async function setTenantLocked(
    id: number,
    locked: boolean,
): Promise<ApiResult<ManagedTenant>> {
    return afterTenantChange(await postTenantLocked(id, locked));
}

export async function inviteAdmin(
    tenantId: number,
    email: string,
): Promise<ApiResult<InvitationResult>> {
    return afterTenantChange(await postInviteAdmin(tenantId, email));
}

export async function resendInvitation(
    tenantId: number,
    userId: number,
): Promise<ApiResult<InvitationResult>> {
    return afterTenantChange(await postResendInvitation(tenantId, userId));
}

export async function setSuperAdminLocked(
    id: number,
    locked: boolean,
): Promise<ApiResult<SuperAdmin>> {
    const result = await postSuperAdminLocked(id, locked);
    if (result.ok || result.error.code === 'not_found' || result.error.code === 'rule_violation') {
        await superAdminList.refresh('changed');
    }
    return result;
}
