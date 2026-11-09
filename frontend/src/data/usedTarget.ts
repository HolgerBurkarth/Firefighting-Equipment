import { ref } from 'vue';

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
    return name === null ? 'Der Standort bleibt.' : `Es kommt in die Sammelstelle ${name}.`;
}
