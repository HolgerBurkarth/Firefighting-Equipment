import { apiUrl } from '../runtime/appBase';

/**
 * Vertragsnummer der API, bei jedem Aufruf als X-Client-Api gesendet. Gleich mit
 * resources/client-api.json und ClientApi::CURRENT im Backend (per Test); nur bei inkompatiblen
 * Änderungen erhöhen (CLAUDE.md, "Versionierung der API für das Frontend").
 */
export const CLIENT_API_HEADER = 'X-Client-Api';
export const CLIENT_API_VERSION = 1;

/** Fehler einer API-Anfrage; `code` ist der Code der API oder "network" bzw. "invalid_response". */
export interface ApiError {
    readonly code: string;
    /** Meldung des Servers (deutsch) oder null. */
    readonly message: string | null;
    /** HTTP-Status oder null bei einem Netzwerkfehler. */
    readonly status: number | null;
    /** Aus Retry-After (nur bei 429), sonst null. */
    readonly retryAfterSeconds: number | null;
    readonly details: Readonly<Record<string, unknown>> | null;
}

export type ApiResult<T> =
    | { readonly ok: true; readonly status: number; readonly data: T }
    | { readonly ok: false; readonly error: ApiError };

export interface ApiRequestOptions {
    readonly method?: 'GET' | 'POST' | 'DELETE';
    /** JSON-Body ändernder Anfragen; ohne Angabe `{}` (das Backend verlangt immer ein Objekt). */
    readonly body?: Readonly<Record<string, unknown>>;
    /**
     * Öffentliche Endpunkte (Anmeldung, Mail-Links …) behandeln 401 selbst (z. B.
     * invalid_credentials). Bei allen anderen meldet eine 401 die abgelaufene Sitzung.
     */
    readonly public?: boolean;
    readonly fetchFn?: typeof fetch;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

let unauthorizedHandler: (() => void) | null = null;

/** Wird bei 401 einer nicht öffentlichen Anfrage aufgerufen (der Router meldet dann ab). */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
    unauthorizedHandler = handler;
}

function apiError(
    code: string,
    status: number | null,
    message: string | null = null,
    retryAfterSeconds: number | null = null,
    details: Record<string, unknown> | null = null,
): ApiError {
    return { code, message, status, retryAfterSeconds, details };
}

/** Retry-After in Sekunden (das Backend sendet immer eine Ganzzahl). */
export function parseRetryAfter(value: string | null): number | null {
    if (value === null || !/^\d{1,9}$/.test(value.trim())) {
        return null;
    }
    return Number(value.trim());
}

function errorFromResponse(response: Response, json: unknown): ApiError {
    const retryAfter =
        response.status === 429 ? parseRetryAfter(response.headers.get('Retry-After')) : null;
    if (!isRecord(json) || typeof json['error'] !== 'string') {
        return apiError('invalid_response', response.status);
    }
    const message = typeof json['message'] === 'string' ? json['message'] : null;
    const details = isRecord(json['details']) ? json['details'] : null;
    return apiError(json['error'], response.status, message, retryAfter, details);
}

/**
 * Anfrage an api/v1/<path> im aktuellen Bereich. `parse` prüft die Antwort bei Erfolg (null =
 * unerwartete Antwort). Wirft nie: Netzwerkfehler und unlesbare Antworten kommen als ApiError.
 */
export async function apiRequest<T>(
    path: string,
    parse: (json: unknown) => T | null,
    options: ApiRequestOptions = {},
): Promise<ApiResult<T>> {
    const method = options.method ?? 'GET';
    const fetchFn = options.fetchFn ?? fetch;
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', [CLIENT_API_HEADER]: String(CLIENT_API_VERSION) },
    };
    if (method !== 'GET') {
        init.headers = {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            [CLIENT_API_HEADER]: String(CLIENT_API_VERSION),
        };
        init.body = JSON.stringify(options.body ?? {});
    }

    let response: Response;
    try {
        response = await fetchFn(apiUrl(path), init);
    } catch {
        return { ok: false, error: apiError('network', null) };
    }

    let json: unknown;
    try {
        json = await response.json();
    } catch {
        json = undefined;
    }

    if (!response.ok) {
        if (response.status === 401 && options.public !== true) {
            unauthorizedHandler?.();
        }
        return { ok: false, error: errorFromResponse(response, json) };
    }
    const data = json === undefined ? null : parse(json);
    return data === null
        ? { ok: false, error: apiError('invalid_response', response.status) }
        : { ok: true, status: response.status, data };
}
