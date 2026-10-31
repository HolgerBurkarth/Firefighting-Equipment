import { shallowRef } from 'vue';
import type { ApiError, ApiResult } from '../api/client';
import { fetchLocations, type Location } from '../api/locations';
import { Refresher, type RefreshReason } from './refresher';

export interface LocationListState {
    /**
     * Standortbaum (`locationStore` ohne gesperrte Standorte, `adminLocationStore` mit); null,
     * solange er noch nie geladen wurde.
     */
    readonly locations: readonly Location[] | null;
    readonly loading: boolean;
    /** Fehler der letzten Auffrischung; der bisherige Baum bleibt dann stehen. */
    readonly error: ApiError | null;
}

const EMPTY: LocationListState = { locations: null, loading: false, error: null };

/**
 * Standorte der Stadt (GET locations) für Umbuchen und Standortwahl; mit eigener Abfrage auch der
 * ganze Baum für die Verwaltung des Admins.
 */
export class LocationStore {
    private readonly current = shallowRef<LocationListState>(EMPTY);
    private readonly refresher: Refresher;
    private generation = 0;
    private readonly loadedListeners = new Set<(locations: readonly Location[]) => void>();

    constructor(
        private readonly fetchTree: () => Promise<ApiResult<readonly Location[]>> = () =>
            fetchLocations(),
        now?: () => number,
    ) {
        this.refresher = new Refresher(() => this.load(), now);
    }

    get state(): LocationListState {
        return this.current.value;
    }

    refresh(reason: RefreshReason): Promise<void> {
        return this.refresher.refresh(reason);
    }

    /** Baum aus der lokalen Kopie (Offline-Modus), nur solange noch nichts geladen ist. */
    restore(locations: readonly Location[]): boolean {
        if (this.current.value.locations !== null) {
            return false;
        }
        this.current.value = { ...this.current.value, locations };
        return true;
    }

    /** Nach jedem erfolgreichen Laden, z. B. für die lokale Kopie. */
    onLoaded(listener: (locations: readonly Location[]) => void): () => void {
        this.loadedListeners.add(listener);
        return () => this.loadedListeners.delete(listener);
    }

    clear(): void {
        this.generation += 1;
        this.refresher.reset();
        this.current.value = EMPTY;
    }

    private async load(): Promise<boolean> {
        const generation = this.generation;
        this.current.value = { ...this.current.value, loading: true };
        const result = await this.fetchTree();
        if (generation !== this.generation) {
            return false;
        }
        this.current.value = result.ok
            ? { locations: result.data, loading: false, error: null }
            : { ...this.current.value, loading: false, error: result.error };
        if (result.ok) {
            for (const listener of this.loadedListeners) {
                listener(result.data);
            }
        }
        return result.ok;
    }
}
