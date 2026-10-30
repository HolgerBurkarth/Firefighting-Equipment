import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchAllDeviceTypes, type DeviceType } from '../api/deviceTypes';
import { Refresher, type RefreshReason } from './refresher';

export interface DeviceTypeListState {
    /** Alle Gerätetypen der Stadt, auch deaktivierte; null, solange noch nie geladen. */
    readonly types: readonly DeviceType[] | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; die bisherige Liste bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: DeviceTypeListState = { types: null, loading: false, error: null };

/** Gerätetypen der Stadt (GET device-types?include_inactive=1) für die Verwaltung des Admins. */
export class DeviceTypeStore {
    private readonly current = shallowRef<DeviceTypeListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;

    constructor(
        private readonly fetchTypes: () => Promise<ApiResult<readonly DeviceType[]>> = () =>
            fetchAllDeviceTypes(),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): DeviceTypeListState {
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
        const result = await this.fetchTypes();
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? { types: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        return result.ok;
    }
}
