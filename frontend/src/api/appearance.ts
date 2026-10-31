import { apiRequest, type ApiResult } from './client';
import { parseTenant, type TenantInfo } from './tenant';

/**
 * Erscheinungsbild der eigenen Stadt (nur Admins, CLAUDE.md "Erscheinungsbild (API)"). Alle drei
 * Routen antworten wie GET api/v1/tenant.
 */

/** POST tenant/appearance {accent_color}: `null` = Standardfarbe. */
export function postAccentColor(
    accentColor: string | null,
    fetchFn?: typeof fetch,
): Promise<ApiResult<TenantInfo>> {
    return apiRequest('tenant/appearance', parseTenant, {
        method: 'POST',
        body: { accent_color: accentColor },
        ...(fetchFn && { fetchFn }),
    });
}

/** POST tenant/logo {file}: PNG mit 512 × 512 Pixeln als Base64. */
export function postLogo(base64: string, fetchFn?: typeof fetch): Promise<ApiResult<TenantInfo>> {
    return apiRequest('tenant/logo', parseTenant, {
        method: 'POST',
        body: { file: base64 },
        ...(fetchFn && { fetchFn }),
    });
}

/** DELETE tenant/logo {}: zurück zum Standard-Symbol (wiederholbar). */
export function deleteLogo(fetchFn?: typeof fetch): Promise<ApiResult<TenantInfo>> {
    return apiRequest('tenant/logo', parseTenant, {
        method: 'DELETE',
        ...(fetchFn && { fetchFn }),
    });
}
