import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchTenantEvents, type TenantEventEntry } from '../api/deviceEvents';
import { fetchDevicePage, type DeviceListItem, type DevicePage } from '../api/devices';
import { fetchDeviceSummary, type DeviceSummaryCounts } from '../api/deviceSummary';
import { allows, type Permissions } from '../api/permissions';
import { fetchLoans, type LoanState } from '../api/loans';
import { fetchStock, type StockState } from '../api/stock';
import { fetchUsers, type AdminUser } from '../api/users';
import { Refresher, type RefreshReason } from './refresher';

/** Höchstens so viele Einträge in „Nicht einsatzbereit“ und „Letzte Aktivitäten“. */
export const NOT_READY_LIMIT = 10;
export const ACTIVITY_LIMIT = 8;

/**
 * Zahlen und Listen der Startseite des Admins (Dashboard) und die Zähler im Menü. Was die Rolle nicht
 * abfragen darf, ist null (Plan Phase 1, Schritt 1.7b; `overviewScope`).
 */
export interface AdminOverview {
    readonly summary: DeviceSummaryCounts | null;
    /** Registrierungen, die auf Freischaltung warten. */
    readonly pendingUsers: number | null;
    /** Defekte zuerst, dann in Wartung (je nach Kennung); `total` = alle nicht einsatzbereiten. */
    readonly notReady: {
        readonly devices: readonly DeviceListItem[];
        readonly total: number;
    } | null;
    readonly activity: readonly TenantEventEntry[] | null;
    /** Verleih (Phase 2, P2.5): offene und überfällige; null ohne den Bereich. */
    readonly loans?: { readonly open: number; readonly overdue: number } | null;
    /** Sollbestand (T21): Standorte mit Regeln und davon „außer Betrieb“; null ohne den Bereich. */
    readonly stock?: { readonly locations: number; readonly outOfService: number } | null;
}

/** Welche der vier Abfragen die Rolle stellen darf (wie die Routen im Backend). */
export interface OverviewScope {
    /** GET device-summary: `dashboard` oder `due` Ansehen. */
    readonly summary: boolean;
    /** GET users?status=pending: `users` Ansehen. */
    readonly pendingUsers: boolean;
    /** GET devices (nur für das Dashboard): `dashboard` und `search` oder `devices` Ansehen. */
    readonly notReady: boolean;
    /** GET device-events: `dashboard` oder `log` Ansehen. */
    readonly activity: boolean;
    /** GET loans: Bereich `loans` Ansehen (nur Städte mit Verleih). */
    readonly loans?: boolean;
    /** GET stock: Bereich `locations` Ansehen. */
    readonly stock?: boolean;
}

export const FULL_SCOPE: OverviewScope = {
    summary: true,
    pendingUsers: true,
    notReady: true,
    activity: true,
};

export function overviewScope(permissions: Permissions | null): OverviewScope {
    const dashboard = allows(permissions, 'dashboard');
    return {
        summary: dashboard || allows(permissions, 'due'),
        pendingUsers: allows(permissions, 'users'),
        notReady: dashboard && (allows(permissions, 'search') || allows(permissions, 'devices')),
        activity: dashboard || allows(permissions, 'log'),
        loans: allows(permissions, 'loans'),
        stock: allows(permissions, 'locations'),
    };
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
    readonly loans?: () => Promise<ApiResult<LoanState>>;
    readonly stock?: () => Promise<ApiResult<StockState>>;
}

const DEFAULT_LOADERS: AdminOverviewLoaders = {
    summary: () => fetchDeviceSummary(),
    pendingUsers: () => fetchUsers('pending'),
    // sort=status absteigend: defekt vor in Wartung (DeviceRepository::orderBy).
    notReady: () =>
        fetchDevicePage({
            limit: NOT_READY_LIMIT,
            offset: 0,
            status: ['maintenance', 'defective', 'used'],
            sort: 'status',
            dir: 'desc',
        }),
    activity: () => fetchTenantEvents({ limit: ACTIVITY_LIMIT, offset: 0 }),
    loans: () => fetchLoans(),
    stock: () => fetchStock(),
};

function loansCounts(state: LoanState | null): { open: number; overdue: number } | null {
    return state === null ? null : { open: state.open, overdue: state.overdue };
}

function stockCounts(state: StockState | null): { locations: number; outOfService: number } | null {
    return state === null
        ? null
        : { locations: state.entries.length, outOfService: state.outOfService };
}

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
        private readonly scope: () => OverviewScope = () => FULL_SCOPE,
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
        const scope = this.scope();
        const skip = Promise.resolve(null);
        const [summary, pendingUsers, notReady, activity, loans, stock] = await Promise.all([
            scope.summary ? this.loaders.summary() : skip,
            scope.pendingUsers ? this.loaders.pendingUsers() : skip,
            scope.notReady ? this.loaders.notReady() : skip,
            scope.activity ? this.loaders.activity() : skip,
            scope.loans === true && this.loaders.loans !== undefined ? this.loaders.loans() : skip,
            scope.stock === true && this.loaders.stock !== undefined ? this.loaders.stock() : skip,
        ]);
        if (generation !== this.generation) {
            return false;
        }
        const results = [summary, pendingUsers, notReady, activity, loans, stock].filter(
            (result) => result !== null,
        );
        const error = firstError(results);
        if (error !== null) {
            this.current.value = { ...this.current.value, loading: false, error };
            return false;
        }
        const data = <T>(result: ApiResult<T> | null): T | null =>
            result?.ok === true ? result.data : null;
        const page = data(notReady);
        this.current.value = {
            overview: {
                summary: data(summary),
                pendingUsers: data(pendingUsers)?.length ?? null,
                notReady: page === null ? null : { devices: page.devices, total: page.total },
                activity: data(activity)?.events ?? null,
                loans: loansCounts(data(loans)),
                stock: stockCounts(data(stock)),
            },
            loading: false,
            error: null,
            loadedAt: this.now(),
        };
        return true;
    }
}
