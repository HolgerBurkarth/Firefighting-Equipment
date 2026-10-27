import { describe, expect, it } from 'vitest';
import {
    applyThemeMode,
    loadThemeMode,
    saveThemeMode,
    THEME_STORAGE_KEY,
} from '../../src/runtime/theme';

function memoryStorage(initial: Record<string, string> = {}): Storage {
    const data = new Map(Object.entries(initial));
    return {
        get length() {
            return data.size;
        },
        clear: () => {
            data.clear();
        },
        getItem: (key) => data.get(key) ?? null,
        key: (index) => [...data.keys()][index] ?? null,
        removeItem: (key) => {
            data.delete(key);
        },
        setItem: (key, value) => {
            data.set(key, value);
        },
    };
}

function blocked(): never {
    throw new Error('gesperrt');
}

const blockedStorage: Storage = {
    length: 0,
    clear: blocked,
    key: blocked,
    getItem: blocked,
    setItem: blocked,
    removeItem: blocked,
};

describe('Anzeigemodus', () => {
    it('ist ohne Eintrag "system"', () => {
        expect(loadThemeMode(memoryStorage())).toBe('system');
        expect(loadThemeMode(null)).toBe('system');
    });

    it('ignoriert unbekannte Werte', () => {
        expect(loadThemeMode(memoryStorage({ [THEME_STORAGE_KEY]: 'blau' }))).toBe('system');
    });

    it('speichert Hell und Dunkel und entfernt den Eintrag bei System', () => {
        const storage = memoryStorage();
        saveThemeMode(storage, 'dark');
        expect(loadThemeMode(storage)).toBe('dark');
        saveThemeMode(storage, 'system');
        expect(storage.getItem(THEME_STORAGE_KEY)).toBeNull();
    });

    it('übersteht einen gesperrten Speicher', () => {
        expect(loadThemeMode(blockedStorage)).toBe('system');
        expect(() => {
            saveThemeMode(blockedStorage, 'light');
        }).not.toThrow();
    });

    it('setzt data-theme nur für Hell und Dunkel', () => {
        const root = document.createElement('html');
        applyThemeMode(root, 'dark');
        expect(root.getAttribute('data-theme')).toBe('dark');
        applyThemeMode(root, 'system');
        expect(root.hasAttribute('data-theme')).toBe(false);
    });
});
