import { readonly, shallowRef } from 'vue';
import type { ApiError } from '../api/client';
import { fetchTenant, type TenantInfo } from '../api/tenant';
import type { AppBase } from './appBase';
import { applyTenantAppearance } from './appearance';

export interface TenantState {
    /** true, bis GET api/v1/tenant beantwortet ist (unter service nie). */
    readonly loading: boolean;
    readonly tenant: TenantInfo | null;
    readonly error: ApiError | null;
}

const state = shallowRef<TenantState>({ loading: true, tenant: null, error: null });

/** Angaben der Stadt für Kopfleiste, Anmeldeseite und Passwortprüfung. */
export const tenantState = readonly(state);

/** Stadt nicht nutzbar: Formulare der Anmeldung werden dann ausgeblendet. */
export function isTenantUnavailable(error: ApiError | null): boolean {
    return error?.code === 'tenant_not_found' || error?.code === 'tenant_inactive';
}

/** Lädt die Stadt einmal beim Start und setzt ihr Erscheinungsbild (unter service nichts). */
export async function loadTenant(
    base: AppBase,
    doc: Document = document,
    fetchFn?: typeof fetch,
): Promise<void> {
    if (base.isService) {
        state.value = { loading: false, tenant: null, error: null };
        return;
    }
    const result = await fetchTenant(fetchFn);
    if (result.ok) {
        applyTenantAppearance(doc, result.data);
        state.value = { loading: false, tenant: result.data, error: null };
    } else {
        state.value = { loading: false, tenant: null, error: result.error };
    }
}
