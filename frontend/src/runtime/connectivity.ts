import { computed, readonly, shallowRef } from 'vue';

/**
 * Erreichbarkeit des Servers (KONZEPT.md, "Offline-Modus – Entscheidungen", 51): offline, solange
 * der Browser offline meldet oder die letzte Anfrage keine Verbindung bekam (bis zur nächsten Antwort
 * des Servers, gleich welcher). Meldungen kommen von apiRequest (src/api/client.ts).
 */
const browserOffline = shallowRef(false);
const lastRequestFailed = shallowRef(false);

export const connectivity = readonly({ browserOffline, lastRequestFailed });

/** true = offline (grauer Streifen, Buchungen in die Warteschlange). */
export const isOffline = computed(() => browserOffline.value || lastRequestFailed.value);

/** Ergebnis einer Anfrage: true = der Server hat geantwortet, false = keine Verbindung. */
export function reportServerReached(reached: boolean): void {
    lastRequestFailed.value = !reached;
}

const onlineListeners = new Set<() => void>();

/** Wird aufgerufen, wenn der Browser wieder online meldet (z. B. Warteschlange senden). */
export function onBackOnline(listener: () => void): () => void {
    onlineListeners.add(listener);
    return () => onlineListeners.delete(listener);
}

/** Verfolgt online/offline des Browsers (in main.ts). Liefert eine Funktion zum Beenden. */
export function installConnectivity(target: Window = window): () => void {
    browserOffline.value = !target.navigator.onLine;
    const online = (): void => {
        browserOffline.value = false;
        for (const listener of onlineListeners) {
            listener();
        }
    };
    const offline = (): void => {
        browserOffline.value = true;
    };
    target.addEventListener('online', online);
    target.addEventListener('offline', offline);
    return () => {
        target.removeEventListener('online', online);
        target.removeEventListener('offline', offline);
    };
}

/** Nur für Tests: Ausgangszustand. */
export function resetConnectivity(): void {
    browserOffline.value = false;
    lastRequestFailed.value = false;
    onlineListeners.clear();
}
