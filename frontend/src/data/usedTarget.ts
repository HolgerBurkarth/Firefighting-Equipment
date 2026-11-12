import { ref } from 'vue';
import type { ApiResult } from '../api/client';
import type { ClientConfig } from '../api/clientConfig';

/**
 * Name der Sammelstelle, in die „Benutzt melden“ bucht (`client-config`, `used_target`): `null` = die
 * Stadt hat keine (der Standort bleibt), `undefined` = noch nicht bekannt. Gemerkt je Stadt in
 * localStorage, damit die Rückfrage auch offline den Ort nennt.
 */
export const usedTargetName = ref<string | null | undefined>(undefined);

export function usedTargetKey(area: string): string {
    return `ff-used-target:${area}`;
}

/** Gemerkten Wert laden (ohne Eintrag bleibt er unbekannt). */
export function loadUsedTarget(area: string, storage: Pick<Storage, 'getItem'> | null): void {
    try {
        const stored = JSON.parse(storage?.getItem(usedTargetKey(area)) ?? 'null') as unknown;
        if (stored === null || typeof stored !== 'object') {
            return;
        }
        const name = (stored as { name?: unknown }).name;
        if (name === null || typeof name === 'string') {
            usedTargetName.value = name;
        }
    } catch {
        // Unbekannt.
    }
}

export function rememberUsedTarget(
    area: string,
    name: string | null,
    storage: Pick<Storage, 'setItem'> | null,
): void {
    usedTargetName.value = name;
    try {
        storage?.setItem(usedTargetKey(area), JSON.stringify({ name }));
    } catch {
        // Nur für diesen Seitenaufruf.
    }
}

/** Zweiter Satz der Rückfrage „Benutzt melden“: wohin es kommt. */
export function usedTargetText(name: string | null | undefined): string {
    if (name === undefined) {
        return 'Es kommt in die Sammelstelle, falls es eine gibt – sonst bleibt der Standort.';
    }
    if (name === null) {
        return 'Der Standort bleibt.';
    }
    // Heißt die Stelle selbst „Sammelstelle“, wiederholt der Satz sie nicht (U20).
    return name.trim().toLowerCase() === 'sammelstelle'
        ? 'Es kommt in die Sammelstelle.'
        : `Es kommt zur Sammelstelle „${name}“.`;
}

function isUnknown(): boolean {
    return usedTargetName.value === undefined;
}

export const USED_TARGET_TIMEOUT_MS = 2000;

/**
 * Vor der Rückfrage „Benutzt melden“: ist die Sammelstelle noch unbekannt (Seite direkt geöffnet),
 * `client-config` kurz laden (mit Frist). Offline oder ohne Antwort bleibt sie unbekannt, dann gilt der
 * Ersatztext.
 */
export async function ensureUsedTarget(options: {
    area: string;
    offline: boolean;
    fetchConfig: () => Promise<ApiResult<ClientConfig>>;
    storage: Pick<Storage, 'setItem'> | null;
    timeoutMs?: number;
}): Promise<void> {
    if (!isUnknown() || options.offline) {
        return;
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    const timeout = new Promise<null>((resolve) => {
        timer = setTimeout(() => {
            resolve(null);
        }, options.timeoutMs ?? USED_TARGET_TIMEOUT_MS);
    });
    try {
        const result = await Promise.race([options.fetchConfig(), timeout]);
        if (result !== null && result.ok && isUnknown()) {
            rememberUsedTarget(options.area, result.data.usedTarget ?? null, options.storage);
        }
    } finally {
        clearTimeout(timer);
    }
}
