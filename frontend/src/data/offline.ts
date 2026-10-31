import { readonly, shallowRef, watch } from 'vue';
import type { AuthState } from '../api/auth';
import type { DeviceListItem } from '../api/devices';
import type { Location } from '../api/locations';
import { onSessionEnded, session } from '../auth/session';
import { browserLocalDb, type LocalDb } from '../runtime/localDb';
import type { DeviceStore } from './deviceStore';
import type { LocationStore } from './locationStore';

/** Format der lokalen Kopie; ein anderes wird ignoriert (und beim nächsten Laden ersetzt). */
export const SNAPSHOT_FORMAT = 1;

/**
 * Lokale Kopie der Daten eines Kontos (KONZEPT.md, "Offline-Modus – Entscheidungen", 49): alle
 * Geräte (nur bis MAX_LOCAL_DEVICES), die Standorte und der Zeitpunkt der letzten vollständigen
 * Synchronisierung (Uhr des Handys, ISO-Zeit in UTC).
 */
export interface OfflineSnapshot {
    readonly format: typeof SNAPSHOT_FORMAT;
    readonly syncedAt: string;
    readonly devices: readonly DeviceListItem[];
    readonly total: number;
    readonly locations: readonly Location[] | null;
}

/** Schlüssel der Kopie: Stadt und Konto (ein anderes Konto auf demselben Handy sieht sie nicht). */
export function snapshotKey(area: string, account: AuthState): string {
    return `${area}:${String(account.user.id)}`;
}

/** Prüft eine gelesene Kopie grob (sie stammt aus dem eigenen Speicher); sonst null. */
export function parseSnapshot(value: unknown): OfflineSnapshot | null {
    if (typeof value !== 'object' || value === null) {
        return null;
    }
    const snapshot = value as Partial<OfflineSnapshot>;
    return snapshot.format === SNAPSHOT_FORMAT &&
        typeof snapshot.syncedAt === 'string' &&
        !Number.isNaN(Date.parse(snapshot.syncedAt)) &&
        Array.isArray(snapshot.devices) &&
        typeof snapshot.total === 'number' &&
        (snapshot.locations === null || Array.isArray(snapshot.locations))
        ? (snapshot as OfflineSnapshot)
        : null;
}

const syncedAt = shallowRef<string | null>(null);

/** Zeitpunkt der angezeigten Daten (letzte vollständige Synchronisierung); null = unbekannt. */
export const offlineData = readonly({ syncedAt });

export interface OfflineCopyOptions {
    readonly area: string;
    readonly devices: DeviceStore;
    readonly locations: LocationStore;
    readonly db?: LocalDb;
    readonly now?: () => Date;
}

/**
 * Hält die lokale Kopie aktuell (main.ts, nur unter Städten): Ist ein Konto bekannt, zeigen die
 * Stores zuerst die Kopie (solange vom Server noch nichts geladen ist); nach jedem vollständigen
 * Laden der Geräte bzw. Standorte wird sie neu gespeichert. Die Standorte lädt die App nicht
 * eigens, sie kommen mit, sobald Umbuchen oder Standortwahl sie geladen haben. Abmelden und ein anderes Konto löschen
 * die Kopie des bisherigen Kontos; eine abgelaufene Sitzung (401) nicht.
 */
export function installOfflineCopy(options: OfflineCopyOptions): () => void {
    const db = options.db ?? browserLocalDb();
    const now = options.now ?? (() => new Date());
    let restoredFor: string | null = null;
    /** Zeitpunkt, zu dem die Geräte dieses Kontos zuletzt vom Server kamen (nicht aus der Kopie). */
    let loadedAt: string | null = null;
    let writes = Promise.resolve();

    const keyNow = (): string | null =>
        session.value === null ? null : snapshotKey(options.area, session.value);

    async function restore(key: string): Promise<void> {
        const snapshot = parseSnapshot(await db.get('snapshots', key));
        if (snapshot === null || keyNow() !== key) {
            return;
        }
        const restored = options.devices.restore(snapshot.devices, snapshot.total);
        if (snapshot.locations !== null) {
            options.locations.restore(snapshot.locations);
        }
        if (restored) {
            syncedAt.value = snapshot.syncedAt;
        }
    }

    /**
     * Speichert den Stand der Stores, sobald die Geräte vom Server vollständig geladen sind (nie die
     * gerade aus der Kopie gelesenen mit neuer Zeit).
     */
    function save(): void {
        const key = keyNow();
        const state = options.devices.state;
        if (key === null || loadedAt === null || state.mode !== 'local' || state.total === null) {
            return;
        }
        const snapshot: OfflineSnapshot = {
            format: SNAPSHOT_FORMAT,
            syncedAt: loadedAt,
            devices: state.devices,
            total: state.total,
            locations: options.locations.state.locations,
        };
        syncedAt.value = snapshot.syncedAt;
        // Nacheinander schreiben, damit eine ältere Kopie nie eine neuere überschreibt.
        writes = writes.then(async () => {
            await db.put('snapshots', key, snapshot);
        });
    }

    const stopSession = watch(
        session,
        (account) => {
            const key = account === null ? null : snapshotKey(options.area, account);
            if (key !== null && key !== restoredFor) {
                restoredFor = key;
                loadedAt = null;
                void restore(key);
            }
            if (key === null) {
                restoredFor = null;
                loadedAt = null;
            }
        },
        { immediate: true },
    );
    const stopDevices = options.devices.onLoaded(() => {
        loadedAt = now().toISOString();
        save();
    });
    const stopLocations = options.locations.onLoaded(save);
    const stopEnded = onSessionEnded((ended, reason) => {
        syncedAt.value = null;
        if (reason !== 'ended') {
            const key = snapshotKey(options.area, ended);
            writes = writes.then(async () => {
                await db.delete('snapshots', key);
            });
        }
    });
    return () => {
        stopSession();
        stopDevices();
        stopLocations();
        stopEnded();
    };
}

/** „31.10., 14:02“ (Ortszeit) für den Streifen „Offline“. */
export function syncedAtText(iso: string): string {
    const time = new Date(iso);
    const day = time.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });
    const clock = time.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
    return `${day}, ${clock}`;
}
