import { apiUrl } from '../runtime/appBase';

/** Öffentliche Angaben einer Stadt aus GET api/v1/tenant. */
export interface TenantInfo {
    readonly slug: string;
    readonly name: string;
    readonly accentColor: string;
    readonly logoUrls: Readonly<Record<'512' | '192' | '180', string>> | null;
}

export type TenantResult =
    | { readonly ok: true; readonly tenant: TenantInfo }
    | { readonly ok: false; readonly error: string };

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function parseTenant(json: unknown): TenantInfo | null {
    if (!isRecord(json)) {
        return null;
    }
    const { slug, name, accent_color: accentColor, logo_urls: logoUrls } = json;
    if (typeof slug !== 'string' || typeof name !== 'string' || typeof accentColor !== 'string') {
        return null;
    }
    if (logoUrls === null) {
        return { slug, name, accentColor, logoUrls: null };
    }
    if (!isRecord(logoUrls)) {
        return null;
    }
    const { '512': l512, '192': l192, '180': l180 } = logoUrls;
    if (typeof l512 !== 'string' || typeof l192 !== 'string' || typeof l180 !== 'string') {
        return null;
    }
    return { slug, name, accentColor, logoUrls: { '512': l512, '192': l192, '180': l180 } };
}

/** Fehlercode der API ("tenant_not_found" …) oder "network" bzw. "invalid_response". */
export async function fetchTenant(fetchFn: typeof fetch = fetch): Promise<TenantResult> {
    let response: Response;
    try {
        response = await fetchFn(apiUrl('tenant'), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
    } catch {
        return { ok: false, error: 'network' };
    }

    let json: unknown;
    try {
        json = await response.json();
    } catch {
        return { ok: false, error: 'invalid_response' };
    }
    if (!response.ok) {
        const code = isRecord(json) && typeof json['error'] === 'string' ? json['error'] : null;
        return { ok: false, error: code ?? 'invalid_response' };
    }
    const tenant = parseTenant(json);
    return tenant === null ? { ok: false, error: 'invalid_response' } : { ok: true, tenant };
}
