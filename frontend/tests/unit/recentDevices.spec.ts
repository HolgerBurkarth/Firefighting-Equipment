import { describe, expect, it } from 'vitest';
import { parseAuthState, type AuthState } from '../../src/api/auth';
import {
    addRecent,
    clearRecent,
    loadRecent,
    MAX_RECENT_DEVICES,
    recentKey,
} from '../../src/data/recentDevices';
import { meBody } from './support/me';

function account(id: number, role: 'user' | 'superadmin' = 'user'): AuthState {
    const body = meBody(role);
    const state = parseAuthState({ ...body, user: { ...(body['user'] as object), id } });
    if (state === null) {
        throw new Error('Ungültige Sitzung');
    }
    return state;
}

function memoryStorage(): Storage {
    const data = new Map<string, string>();
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

describe('Zuletzt benutzt', () => {
    it('Schlüssel je Stadt und Konto, keiner für den Super-Admin', () => {
        expect(recentKey(account(7))).toBe('ff-recent-devices:test:7');
        expect(recentKey(account(1, 'superadmin'))).toBeNull();
    });

    it('neueste zuerst, ohne Doppelte, höchstens 8', () => {
        const storage = memoryStorage();
        const anna = account(7);
        for (const id of [1, 2, 3, 2]) {
            addRecent(storage, anna, id);
        }
        expect(loadRecent(storage, anna)).toEqual([2, 3, 1]);
        for (let id = 10; id < 20; id += 1) {
            addRecent(storage, anna, id);
        }
        expect(MAX_RECENT_DEVICES).toBe(8);
        expect(loadRecent(storage, anna)).toEqual([19, 18, 17, 16, 15, 14, 13, 12]);
    });

    it('getrennt je Konto; Löschen betrifft nur das eigene', () => {
        const storage = memoryStorage();
        addRecent(storage, account(7), 1);
        addRecent(storage, account(8), 2);
        clearRecent(storage, account(7));
        expect(loadRecent(storage, account(7))).toEqual([]);
        expect(loadRecent(storage, account(8))).toEqual([2]);
    });

    it.each(['kein json', '{"a":1}', '[1,"2"]', '[0]', '[1.5]'])(
        'ungültiger Inhalt %s → leer',
        (stored) => {
            const storage = memoryStorage();
            storage.setItem('ff-recent-devices:test:7', stored);
            expect(loadRecent(storage, account(7))).toEqual([]);
        },
    );

    it('ohne oder mit gesperrtem Speicher kein Fehler', () => {
        const blocked: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'> = {
            getItem: () => {
                throw new Error('gesperrt');
            },
            setItem: () => {
                throw new Error('gesperrt');
            },
            removeItem: () => {
                throw new Error('gesperrt');
            },
        };
        expect(loadRecent(null, account(7))).toEqual([]);
        expect(loadRecent(blocked, account(7))).toEqual([]);
        expect(addRecent(blocked, account(7), 3)).toEqual([3]);
        expect(() => {
            clearRecent(blocked, account(7));
        }).not.toThrow();
    });
});
