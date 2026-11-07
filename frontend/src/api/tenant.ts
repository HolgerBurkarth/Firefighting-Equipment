import { apiRequest, isRecord, type ApiResult } from './client';

/** Öffentliche Angaben einer Stadt aus GET api/v1/tenant. */
export interface TenantInfo {
    readonly slug: string;
    readonly name: string;
    readonly accentColor: string;
    readonly logoUrls: Readonly<Record<'512' | '192' | '180', string>> | null;
    /** Claude-Zugang verfügbar (bisher nur die Test-Stadt); fehlt das Feld, nein. */
    readonly claudeAvailable: boolean;
}

export function parseTenant(json: unknown): TenantInfo | null {
    if (!isRecord(json)) {
        return null;
    }
    const { slug, name, accent_color: accentColor, logo_urls: logoUrls } = json;
    const claudeAvailable = json['claude_available'] ?? false;
    if (
        typeof slug !== 'string' ||
        typeof name !== 'string' ||
        typeof accentColor !== 'string' ||
        typeof claudeAvailable !== 'boolean'
    ) {
        return null;
    }
    if (logoUrls === null) {
        return { slug, name, accentColor, logoUrls: null, claudeAvailable };
    }
    if (!isRecord(logoUrls)) {
        return null;
    }
    const { '512': l512, '192': l192, '180': l180 } = logoUrls;
    if (typeof l512 !== 'string' || typeof l192 !== 'string' || typeof l180 !== 'string') {
        return null;
    }
    return {
        slug,
        name,
        accentColor,
        logoUrls: { '512': l512, '192': l192, '180': l180 },
        claudeAvailable,
    };
}

export function fetchTenant(fetchFn?: typeof fetch): Promise<ApiResult<TenantInfo>> {
    return apiRequest('tenant', parseTenant, { public: true, ...(fetchFn && { fetchFn }) });
}
