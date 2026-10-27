export type ThemeMode = 'system' | 'light' | 'dark';

/** Anzeigemodus je Gerät (Browser), nicht je Konto. */
export const THEME_STORAGE_KEY = 'ff-theme';

const MODES: readonly ThemeMode[] = ['system', 'light', 'dark'];

function isThemeMode(value: unknown): value is ThemeMode {
    return MODES.includes(value as ThemeMode);
}

/** Gespeicherter Modus; ohne Eintrag, bei unbekanntem Wert oder gesperrtem Speicher "system". */
export function loadThemeMode(storage: Pick<Storage, 'getItem'> | null): ThemeMode {
    try {
        const value = storage?.getItem(THEME_STORAGE_KEY);
        return isThemeMode(value) ? value : 'system';
    } catch {
        return 'system';
    }
}

export function saveThemeMode(
    storage: Pick<Storage, 'setItem' | 'removeItem'> | null,
    mode: ThemeMode,
): void {
    try {
        if (mode === 'system') {
            storage?.removeItem(THEME_STORAGE_KEY);
        } else {
            storage?.setItem(THEME_STORAGE_KEY, mode);
        }
    } catch {
        // Privater Modus o. Ä.: Die Wahl gilt dann nur bis zum Neuladen.
    }
}

/** "system" entfernt data-theme, dann gilt prefers-color-scheme (siehe tokens.css). */
export function applyThemeMode(root: HTMLElement, mode: ThemeMode): void {
    if (mode === 'system') {
        root.removeAttribute('data-theme');
    } else {
        root.setAttribute('data-theme', mode);
    }
}

export function browserStorage(): Storage | null {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}
