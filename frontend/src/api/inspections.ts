import { apiRequest, isRecord, type ApiResult } from './client';
import { parseDeviceSummary, type DeviceSummary } from './devices';

/** Gerät eines Abschnitts mit Tagen (überfällig seit bzw. fällig in). */
export interface InspectionEntry {
    readonly device: DeviceSummary;
    /** Überfällig: Tage seit dem Prüfdatum (ab 1); bald fällig: Tage bis dahin (0 = heute). */
    readonly days: number;
}

export interface InspectionSection {
    readonly entries: readonly InspectionEntry[];
    /** Alle Geräte des Abschnitts (auf dem Server ggf. mehr als `entries`). */
    readonly total: number;
}

/** Prüffristen: überfällig und in den nächsten `days` Tagen fällig (ohne ausgesonderte). */
export interface InspectionOverview {
    /** JJJJ-MM-TT. */
    readonly today: string;
    readonly days: number;
    readonly overdue: InspectionSection;
    readonly dueSoon: InspectionSection;
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function parseSection(json: unknown, daysKey: string, minDays: number): InspectionSection | null {
    if (!isRecord(json) || !Array.isArray(json['devices']) || !isCount(json['total'])) {
        return null;
    }
    const entries: InspectionEntry[] = [];
    for (const entry of json['devices']) {
        const device = parseDeviceSummary(entry);
        const days = isRecord(entry) ? entry[daysKey] : undefined;
        if (device === null || !isCount(days) || days < minDays) {
            return null;
        }
        entries.push({ device, days });
    }
    return { entries, total: json['total'] };
}

/** Antwort von GET inspection-due (InspectionDueHandler). */
export function parseInspectionOverview(json: unknown): InspectionOverview | null {
    if (!isRecord(json) || typeof json['today'] !== 'string' || !isCount(json['days'])) {
        return null;
    }
    const overdue = parseSection(json['overdue'], 'days_overdue', 1);
    const dueSoon = parseSection(json['due_soon'], 'days_until_due', 0);
    if (overdue === null || dueSoon === null) {
        return null;
    }
    return { today: json['today'], days: json['days'], overdue, dueSoon };
}

/** GET inspection-due: Fenster `days`, höchstens `limit` Geräte je Abschnitt. */
export function fetchInspectionOverview(
    query: { readonly days: number; readonly limit: number },
    fetchFn?: typeof fetch,
): Promise<ApiResult<InspectionOverview>> {
    const params = new URLSearchParams({ days: String(query.days), limit: String(query.limit) });
    return apiRequest(`inspection-due?${params.toString()}`, parseInspectionOverview, {
        ...(fetchFn && { fetchFn }),
    });
}
