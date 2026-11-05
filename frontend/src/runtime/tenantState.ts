import { readonly, shallowRef } from 'vue';
import { deleteLogo, postAccentColor, postLogo } from '../api/appearance';
import type { ApiError, ApiResult } from '../api/client';
import { fetchTenant, type TenantInfo } from '../api/tenant';
import type { AppBase } from './appBase';
import { applyAccent } from './accent';
import { applyTenantAppearance } from './appearance';
import { rememberTenant } from './splash';
import { browserStorage } from './theme';

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
        setTenant(result.data, doc);
    } else {
        // Die Farbe aus dem letzten Besuch (Startbild) gilt nicht mehr; ist die Stadt unbekannt
        // oder gesperrt, auch der gemerkte Stand nicht.
        applyAccent(doc.documentElement, null);
        if (isTenantUnavailable(result.error)) {
            rememberTenant(browserStorage(), base.area, null);
        }
        state.value = { loading: false, tenant: null, error: result.error };
    }
}

/** Übernimmt einen neuen Stand der Stadt (Antwort einer Änderung) samt Erscheinungsbild. */
export function setTenant(tenant: TenantInfo, doc: Document = document): void {
    applyTenantAppearance(doc, tenant);
    // Für das Startbild des nächsten Starts (src/runtime/splash.ts).
    rememberTenant(browserStorage(), tenant.slug, {
        name: tenant.name,
        accentColor: tenant.accentColor,
    });
    state.value = { loading: false, tenant, error: null };
}

async function update(request: Promise<ApiResult<TenantInfo>>): Promise<ApiResult<TenantInfo>> {
    const result = await request;
    if (result.ok) {
        setTenant(result.data);
    }
    return result;
}

/**
 * Erscheinungsbild der Stadt ändern (Einstellungen, nur Admins): Bei Erfolg gelten Farbe, Logo und
 * App-Symbol sofort in Menü, Kopfleiste und Titel (`null` = Standardfarbe).
 */
export function saveAccentColor(accentColor: string | null): Promise<ApiResult<TenantInfo>> {
    return update(postAccentColor(accentColor));
}

/** Logo hochladen (PNG 512 × 512 als Base64). */
export function saveLogo(base64: string): Promise<ApiResult<TenantInfo>> {
    return update(postLogo(base64));
}

/** Logo entfernen: zurück zum Standard-Symbol. */
export function removeLogo(): Promise<ApiResult<TenantInfo>> {
    return update(deleteLogo());
}
