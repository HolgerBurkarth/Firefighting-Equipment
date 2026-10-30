import { apiRequest, isRecord, type ApiResult } from './client';
import type { BookableStatus } from './devices';

/** Antwort von GET device-summary (DeviceSummaryHandler), ohne ausgesonderte Geräte. */
export interface DeviceSummaryCounts {
    /** JJJJ-MM-TT (Europe/Berlin). */
    readonly today: string;
    readonly total: number;
    readonly byStatus: Readonly<Record<BookableStatus, number>>;
    readonly overdue: number;
    readonly dueSoon: number;
    readonly dueSoonDays: number;
    readonly withoutDate: number;
    /** Offene Hinweise (nur für Admins geliefert, sonst null). */
    readonly openNotices: number | null;
}

function isCount(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
}

function parseByStatus(json: unknown): Record<BookableStatus, number> | null {
    if (!Array.isArray(json)) {
        return null;
    }
    const counts: Partial<Record<BookableStatus, number>> = {};
    for (const entry of json) {
        if (!isRecord(entry) || !isCount(entry['count'])) {
            return null;
        }
        const status = entry['status'];
        if (status !== 'ready' && status !== 'maintenance' && status !== 'defective') {
            return null;
        }
        counts[status] = entry['count'];
    }
    const { ready, maintenance, defective } = counts;
    return ready === undefined || maintenance === undefined || defective === undefined
        ? null
        : { ready, maintenance, defective };
}

export function parseDeviceSummaryCounts(json: unknown): DeviceSummaryCounts | null {
    if (!isRecord(json) || !isRecord(json['devices']) || !isRecord(json['inspection'])) {
        return null;
    }
    const { today, devices, inspection, notices } = json;
    const byStatus = parseByStatus(devices['by_status']);
    const openNotices = isRecord(notices) ? notices['open'] : null;
    if (
        typeof today !== 'string' ||
        !isCount(devices['total']) ||
        byStatus === null ||
        !isCount(inspection['overdue']) ||
        !isCount(inspection['due_soon']) ||
        !isCount(inspection['due_soon_days']) ||
        !isCount(inspection['without_date']) ||
        (openNotices !== null && !isCount(openNotices)) ||
        (notices !== undefined && !isRecord(notices))
    ) {
        return null;
    }
    return {
        today,
        total: devices['total'],
        byStatus,
        overdue: inspection['overdue'],
        dueSoon: inspection['due_soon'],
        dueSoonDays: inspection['due_soon_days'],
        withoutDate: inspection['without_date'],
        openNotices,
    };
}

/** GET device-summary: Zahlen für das Dashboard (eine Abfrage, für Admins zwei). */
export function fetchDeviceSummary(
    fetchFn?: typeof fetch,
): Promise<ApiResult<DeviceSummaryCounts>> {
    return apiRequest('device-summary', parseDeviceSummaryCounts, {
        ...(fetchFn && { fetchFn }),
    });
}
