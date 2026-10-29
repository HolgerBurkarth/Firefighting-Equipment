import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import {
    fetchDeviceEvents,
    type DeviceEventEntry,
    type DeviceEventPage,
} from '../api/deviceEvents';

/** Einträge je Abruf („Weitere Einträge laden“). */
export const EVENT_PAGE_LIMIT = 20;

export interface DeviceHistoryState {
    /** Neueste zuerst (nach Eingang beim Server). */
    readonly events: readonly DeviceEventEntry[];
    /** Anzahl laut Server, null vor dem ersten Laden. */
    readonly total: number | null;
    readonly loading: boolean;
    /** Fehler des letzten Abrufs; schon geladene Einträge bleiben stehen. */
    readonly error: ApiError | null;
}

type FetchEvents = (
    id: number,
    query: { readonly limit: number; readonly offset: number },
) => Promise<ApiResult<DeviceEventPage>>;

const EMPTY: DeviceHistoryState = { events: [], total: null, loading: false, error: null };

/**
 * Verlauf eines Geräts für „Details und Verlauf“ (GET devices/{id}/events), seitenweise. Kommen
 * zwischen zwei Abrufen neue Einträge hinzu, verschieben sie die Seiten nur nach hinten: doppelte
 * werden entfernt, es fehlt keiner.
 */
export class DeviceHistory {
    private readonly current = shallowRef<DeviceHistoryState>(EMPTY);
    private sequence = 0;

    constructor(
        private readonly deviceId: number,
        private readonly fetchEvents: FetchEvents = (id, query) => fetchDeviceEvents(id, query),
    ) {}

    get state(): DeviceHistoryState {
        return this.current.value;
    }

    get hasMore(): boolean {
        const { total, events } = this.current.value;
        return total !== null && events.length < total;
    }

    /** Erste Seite (neu), z. B. beim Öffnen. */
    reload(): Promise<void> {
        return this.fetch(0, true);
    }

    /** Nächste Seite anhängen. */
    loadMore(): Promise<void> {
        return this.fetch(this.current.value.events.length, false);
    }

    private async fetch(offset: number, replace: boolean): Promise<void> {
        const sequence = ++this.sequence;
        this.current.value = { ...this.current.value, loading: true, error: null };
        const result = await this.fetchEvents(this.deviceId, { limit: EVENT_PAGE_LIMIT, offset });
        if (sequence !== this.sequence) {
            return;
        }
        const state = this.current.value;
        if (!result.ok) {
            this.current.value = { ...state, loading: false, error: result.error };
            return;
        }
        const known = replace ? [] : state.events;
        const ids = new Set(known.map((event) => event.id));
        this.current.value = {
            events: [...known, ...result.data.events.filter((event) => !ids.has(event.id))],
            total: result.data.total,
            loading: false,
            error: null,
        };
    }
}
