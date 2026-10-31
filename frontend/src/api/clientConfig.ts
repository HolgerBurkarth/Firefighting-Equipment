import { apiRequest, isRecord, type ApiResult } from './client';
import { isUtcTime } from './devices';

/** Grenzen des Offline-Modus (GET client-config, ClientConfigHandler). */
export interface ClientConfig {
    /** Uhr des Servers (UTC), z. B. um eine falsch gehende Uhr zu erkennen. */
    readonly serverTime: string;
    /** Offline buchen nur, wenn die letzte Synchronisierung höchstens so viele Tage zurückliegt. */
    readonly maxAgeDays: number;
    /** Ältere Buchungen der Warteschlange gehen nur nach Rückfrage raus (`confirmed_stale`). */
    readonly queueMaxAgeDays: number;
    readonly maxClockSkewSeconds: number;
}

function isDays(value: unknown): value is number {
    return typeof value === 'number' && Number.isInteger(value) && value >= 1 && value <= 365;
}

export function parseClientConfig(json: unknown): ClientConfig | null {
    if (!isRecord(json) || !isRecord(json['offline'])) {
        return null;
    }
    const serverTime = json['server_time'];
    const skew = json['max_clock_skew_seconds'];
    const maxAgeDays = json['offline']['max_age_days'];
    const queueMaxAgeDays = json['offline']['queue_max_age_days'];
    if (
        !isUtcTime(serverTime) ||
        !isDays(maxAgeDays) ||
        !isDays(queueMaxAgeDays) ||
        typeof skew !== 'number' ||
        !Number.isInteger(skew) ||
        skew < 0
    ) {
        return null;
    }
    return { serverTime, maxAgeDays, queueMaxAgeDays, maxClockSkewSeconds: skew };
}

/** GET client-config (angemeldet). */
export function fetchClientConfig(fetchFn?: typeof fetch): Promise<ApiResult<ClientConfig>> {
    return apiRequest('client-config', parseClientConfig, { ...(fetchFn && { fetchFn }) });
}
