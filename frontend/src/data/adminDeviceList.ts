import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import {
    DEVICE_PAGE_LIMIT,
    fetchDevicePage,
    type DeviceListItem,
    type DevicePage,
    type DevicePageQuery,
} from '../api/devices';
import { deviceListParams, filterKey, type DeviceListFilter } from './deviceList';
import { Refresher, type RefreshReason } from './refresher';

/** Geräte je Seite der Liste („Weitere laden“ holt die nächsten). */
export const ADMIN_DEVICE_PAGE_SIZE = 50;

export interface AdminDeviceListState {
    /** Filter der angezeigten Liste; null, solange noch keiner gesetzt ist. */
    readonly filter: DeviceListFilter | null;
    /** Geladene Geräte in der Reihenfolge des Servers. */
    readonly devices: readonly DeviceListItem[];
    /** Anzahl laut Server für den Filter; null, solange für ihn noch nichts geladen ist. */
    readonly total: number | null;
    readonly loading: boolean;
    readonly loadingMore: boolean;
    /** Fehler der letzten Abfrage; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: AdminDeviceListState = {
    filter: null,
    devices: [],
    total: null,
    loading: false,
    loadingMore: false,
    error: null,
};

/**
 * Geräteliste der Admin-Konsole (GET devices mit Filter, Sortierung und Seiten vom Server, auch
 * ausgesonderte und mehr als 2.000 Geräte). Anders als `deviceStore` keine lokale Kopie: Filter und
 * Sortierung rechnet der Server, genau wie beim Export. Antworten zu einem älteren Filter werden
 * verworfen.
 */
export class AdminDeviceList {
    private readonly current = shallowRef<AdminDeviceListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchPage: (query: DevicePageQuery) => Promise<ApiResult<DevicePage>> = (
            query,
        ) => fetchDevicePage(query),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): AdminDeviceListState {
        return this.current.value;
    }

    /**
     * Liste für `filter` zeigen: Neuer Filter → erste Seite neu (die bisherige Liste verschwindet);
     * gleicher Filter → nur auffrischen wie beim Öffnen einer Seite.
     */
    show(filter: DeviceListFilter): Promise<void> {
        const shown = this.current.value.filter;
        if (shown !== null && filterKey(shown) === filterKey(filter)) {
            this.current.value = { ...this.current.value, filter };
            return this.refresher.refresh('open');
        }
        this.generation += 1;
        this.current.value = { ...EMPTY, filter };
        return this.refresher.refresh('changed');
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.current.value.filter === null
            ? Promise.resolve()
            : this.refresher.refresh(reason);
    }

    /** Nächste Seite anhängen (doppelte IDs entfernt, falls sich die Liste inzwischen verschob). */
    async more(): Promise<void> {
        const state = this.current.value;
        if (state.filter === null || state.loading || state.loadingMore) {
            return;
        }
        const generation = this.generation;
        this.current.value = { ...state, loadingMore: true };
        const result = await this.fetchPage({
            ...deviceListParams(state.filter),
            limit: ADMIN_DEVICE_PAGE_SIZE,
            offset: state.devices.length,
        });
        if (generation !== this.generation) {
            return;
        }
        const now = this.current.value;
        if (!result.ok) {
            this.current.value = { ...now, loadingMore: false, error: result.error };
            return;
        }
        const known = new Set(now.devices.map((device) => device.id));
        this.current.value = {
            ...now,
            devices: [...now.devices, ...result.data.devices.filter((d) => !known.has(d.id))],
            total: result.data.total,
            loadingMore: false,
            error: null,
        };
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    /** Erste Seite neu laden; beim Auffrischen so viele wie schon geladen (höchstens 200). */
    private async load(): Promise<boolean> {
        const generation = this.generation;
        const state = this.current.value;
        if (state.filter === null) {
            return false;
        }
        this.current.value = { ...state, loading: true };
        const limit = Math.min(
            DEVICE_PAGE_LIMIT,
            Math.max(ADMIN_DEVICE_PAGE_SIZE, state.devices.length),
        );
        const result = await this.fetchPage({
            ...deviceListParams(state.filter),
            limit,
            offset: 0,
        });
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? {
                  ...this.current.value,
                  devices: result.data.devices,
                  total: result.data.total,
                  loading: false,
                  loadingMore: false,
                  error: null,
              }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}
