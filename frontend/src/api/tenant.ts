import { apiRequest, isRecord, type ApiResult } from './client';

/** Öffentliche Angaben einer Stadt aus GET api/v1/tenant. */
export interface TenantInfo {
    readonly slug: string;
    readonly name: string;
    readonly accentColor: string;
    readonly logoUrls: Readonly<Record<'512' | '192' | '180', string>> | null;
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

export function fetchTenant(fetchFn?: typeof fetch): Promise<ApiResult<TenantInfo>> {
    return apiRequest('tenant', parseTenant, { public: true, ...(fetchFn && { fetchFn }) });
}
