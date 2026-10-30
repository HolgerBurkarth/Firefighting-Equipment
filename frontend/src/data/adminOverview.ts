import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchTenantEvents, type TenantEventEntry } from '../api/deviceEvents';
import { fetchDevicePage, type DeviceListItem, type DevicePage } from '../api/devices';
import { fetchDeviceSummary, type DeviceSummaryCounts } from '../api/deviceSummary';
import { fetchUsers, type AdminUser } from '../api/users';
import { Refresher, type RefreshReason } from './refresher';

/** Höchstens so viele Einträge in „Nicht einsatzbereit“ und „Letzte Aktivitäten“. */
export const NOT_READY_LIMIT = 10;
export const ACTIVITY_LIMIT = 8;

/** Zahlen und Listen der Startseite des Admins (Dashboard) und die Zähler im Menü. */
export interface AdminOverview {
    readonly summary: DeviceSummaryCounts;
    /** Registrierungen, die auf Freischaltung warten. */
    readonly pendingUsers: number;
    /** Defekte zuerst, dann in Wartung (je nach Kennung); `total` = alle nicht einsatzbereiten. */
    readonly notReady: { readonly devices: readonly DeviceListItem[]; readonly total: number };
    readonly activity: readonly TenantEventEntry[];
}

export interface AdminOverviewState {
    /** null, solange noch nie geladen. */
    readonly overview: AdminOverview | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; der bisherige Stand bleibt dann stehen. */
    readonly error: ApiError | null;
    /** Zeitpunkt (ms) des letzten Erfolgs, für „Stand 08:24“. */
    readonly loadedAt: number | null;
}

export interface AdminOverviewLoaders {
    readonly summary: () => Promise<ApiResult<DeviceSummaryCounts>>;
    readonly pendingUsers: () => Promise<ApiResult<readonly AdminUser[]>>;
    readonly notReady: () => Promise<ApiResult<DevicePage>>;
    readonly activity: () => Promise<ApiResult<{ readonly events: readonly TenantEventEntry[] }>>;
}

const DEFAULT_LOADERS: AdminOverviewLoaders = {
    summary: () => fetchDeviceSummary(),
    pendingUsers: () => fetchUsers('pending'),
    // sort=status absteigend: defekt vor in Wartung (DeviceRepository::orderBy).
    notReady: () =>
        fetchDevicePage({
            limit: NOT_READY_LIMIT,
            offset: 0,
            status: ['maintenance', 'defective'],
            sort: 'status',
            dir: 'desc',
        }),
    activity: () => fetchTenantEvents({ limit: ACTIVITY_LIMIT, offset: 0 }),
};

function firstError(results: readonly ApiResult<unknown>[]): ApiError | null {
    for (const result of results) {
        if (!result.ok) {
            return result.error;
        }
    }
    return null;
}

const EMPTY: AdminOverviewState = { overview: null, loading: false, error: null, loadedAt: null };

/**
 * Lädt die vier Quellen des Dashboards gleichzeitig; nur wenn alle antworten, gilt der neue Stand
 * (sonst bleibt der bisherige mit `error`). Auffrischen wie die anderen Stores über `Refresher`.
 */
export class AdminOverviewStore {
    private readonly current = shallowRef<AdminOverviewState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly loaders: AdminOverviewLoaders = DEFAULT_LOADERS,
        private readonly now: () => number = Date.now,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): AdminOverviewState {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        const [summary, pendingUsers, notReady, activity] = await Promise.all([
            this.loaders.summary(),
            this.loaders.pendingUsers(),
            this.loaders.notReady(),
            this.loaders.activity(),
        ]);
        if (generation !== this.generation) {
            return false;
        }
        if (!summary.ok || !pendingUsers.ok || !notReady.ok || !activity.ok) {
            this.current.value = {
                ...this.current.value,
                loading: false,
                error: firstError([summary, pendingUsers, notReady, activity]),
            };
            return false;
        }
        this.current.value = {
            overview: {
                summary: summary.data,
                pendingUsers: pendingUsers.data.length,
                notReady: { devices: notReady.data.devices, total: notReady.data.total },
                activity: activity.data.events,
            },
            loading: false,
            error: null,
            loadedAt: this.now(),
        };
        return true;
    }
}
