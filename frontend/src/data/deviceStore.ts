import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import {
    DEVICE_PAGE_LIMIT,
    fetchDevicePage,
    type DeviceListItem,
    type DevicePage,
    type DevicePageQuery,
} from '../api/devices';
import { Refresher, type RefreshReason } from './refresher';

/**
 * Bis zu so vielen Geräten lädt die App die ganze Liste und sucht lokal; darüber sucht sie auf dem
 * Server (KONZEPT.md, "User am Handy – Entscheidungen", 14).
 */
export const MAX_LOCAL_DEVICES = 2000;

/** `local`: alle Geräte geladen; `server`: zu viele, die Suche fragt den Server. */
export type DeviceListMode = 'local' | 'server';

export interface DeviceListState {
    /** null, solange die Liste noch nie geladen wurde. */
    readonly mode: DeviceListMode | null;
    /** Alle nicht ausgesonderten Geräte in der Reihenfolge des Servers (nur `local`, sonst leer). */
    readonly devices: readonly DeviceListItem[];
    /** Anzahl laut Server (auch im Modus `server`), null vor dem ersten Laden. */
    readonly total: number | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: DeviceListState = {
    mode: null,
    devices: [],
    total: null,
    loading: false,
    error: null,
};

type FetchPage = (query: DevicePageQuery) => Promise<ApiResult<DevicePage>>;

type LoadResult =
    | { readonly ok: false; readonly error: ApiError }
    | { readonly ok: true; readonly mode: 'server'; readonly total: number }
    | {
          readonly ok: true;
          readonly mode: 'local';
          readonly total: number;
          readonly devices: readonly DeviceListItem[];
          /** false, wenn sich der Bestand während des Ladens geändert hat. */
          readonly consistent: boolean;
      };

/**
 * Geräteliste der Stadt, geladen in Blöcken zu 200 (GET devices). Später die lokale Kopie für den
 * Offline-Modus; Seiten lesen Geräte nur hier, nie direkt über die API.
 */
export class DeviceStore {
    private readonly current = shallowRef<DeviceListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchPage: FetchPage = (query) => fetchDevicePage(query),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    /** Reaktiver Stand (shallowRef: Komponenten zeichnen bei jeder neuen Liste neu). */
    get state(): DeviceListState {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    find(id: number): DeviceListItem | null {
        return this.current.value.devices.find((device) => device.id === id) ?? null;
    }

    /**
     * Übernimmt den Stand eines Geräts aus einer Antwort des Servers (z. B. nach einer Buchung),
     * bevor die Liste neu geladen ist. Ältere Versionen werden ignoriert; ausgesonderte fallen
     * heraus (GET devices zeigt sie nicht).
     */
    upsert(device: DeviceListItem): void {
        const state = this.current.value;
        const index = state.devices.findIndex((entry) => entry.id === device.id);
        if (state.mode !== 'local' || index < 0) {
            return;
        }
        if ((state.devices[index]?.version ?? 0) > device.version) {
            return;
        }
        const devices = [...state.devices];
        if (device.status === 'retired') {
            devices.splice(index, 1);
        } else {
            devices[index] = device;
        }
        this.current.value = { ...state, devices };
    }

    /** Vergisst alle Geräte (Abmelden, Sitzung abgelaufen). */
    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        let result = await this.loadAll();
        // Hat sich der Bestand während des Ladens geändert (Seiten verschoben), einmal neu laden.
        if (result.ok && result.mode === 'local' && !result.consistent) {
            result = await this.loadAll();
        }
        if (generation !== this.generation) {
            return false;
        }
        const state = this.current.value;
        if (!result.ok) {
            this.current.value = { ...state, loading: false, error: result.error };
            return false;
        }
        this.current.value =
            result.mode === 'server'
                ? { mode: 'server', devices: [], total: result.total, loading: false, error: null }
                : {
                      mode: 'local',
                      devices: result.devices,
                      total: result.total,
                      loading: false,
                      error: null,
                  };
        return true;
    }

    private async loadAll(): Promise<LoadResult> {
        const first = await this.fetchPage({ limit: DEVICE_PAGE_LIMIT, offset: 0 });
        if (!first.ok) {
            return first;
        }
        const total = first.data.total;
        if (total > MAX_LOCAL_DEVICES) {
            return { ok: true, mode: 'server', total };
        }
        const byId = new Map<number, DeviceListItem>();
        let consistent = true;
        let page = first.data;
        for (let offset = 0; ;) {
            for (const device of page.devices) {
                byId.set(device.id, device);
            }
            consistent &&= page.total === total;
            offset += DEVICE_PAGE_LIMIT;
            if (offset >= total || page.devices.length < DEVICE_PAGE_LIMIT) {
                break;
            }
            const next = await this.fetchPage({ limit: DEVICE_PAGE_LIMIT, offset });
            if (!next.ok) {
                return next;
            }
            page = next.data;
        }
        consistent &&= byId.size === total;
        return { ok: true, mode: 'local', total, devices: [...byId.values()], consistent };
    }
}
