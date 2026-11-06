import { describe, expect, it } from 'vitest';
import type { AuthState } from '../../src/api/auth';
import {
    ACCOUNTS_FORMAT,
    ACCOUNTS_KEY,
    accountEntries,
    accountHints,
    accountInitials,
    accountStateLabel,
    cityFromInput,
    cityUrl,
    forgetAccount,
    readAccounts,
    rememberAccount,
} from '../../src/data/accounts';
import { storedQueue } from '../../src/data/bookingQueue';
import { MemoryDb } from '../../src/runtime/localDb';

/**
 * Konten in mehreren Städten auf diesem Gerät („Konto wechseln“, Plan Phase 1, Schritt 1.9).
 */
class MemoryStorage {
    readonly items = new Map<string, string>();
    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }
    setItem(key: string, value: string): void {
        this.items.set(key, value);
    }
    removeItem(key: string): void {
        this.items.delete(key);
    }
}

function account(slug: string, name: string, id = 7, roleName: string | null = 'Admin'): AuthState {
    return {
        user: {
            id,
            email: 'anna@example.org',
            name: 'Anna Beispiel',
            displayName: 'Anna',
            role: 'admin',
            roleName,
        },
        tenant: { slug, name },
        permissions: null,
    };
}

/** Gespeicherte Sitzung einer Stadt wie session.ts sie schreibt (Format von auth/me). */
function storeSession(storage: MemoryStorage, slug: string, id: number): void {
    storage.setItem(
        `ff-session:${slug}`,
        JSON.stringify({
            user: { id, email: null, name: 'Anna', display_name: null, role: 'user' },
            tenant: { slug, name: slug },
        }),
    );
}

describe('Liste der Konten', () => {
    it('merkt sich je Stadt ein Konto, sortiert nach Name, ohne E-Mail', () => {
        const storage = new MemoryStorage();
        rememberAccount(storage, account('tuttlingen', 'FF Tuttlingen'));
        rememberAccount(storage, account('hattingen', 'FF Hattingen', 9, 'Benutzer'));

        expect(readAccounts(storage)).toEqual([
            {
                slug: 'hattingen',
                tenantName: 'FF Hattingen',
                accountId: 9,
                displayName: 'Anna',
                roleName: 'Benutzer',
            },
            {
                slug: 'tuttlingen',
                tenantName: 'FF Tuttlingen',
                accountId: 7,
                displayName: 'Anna',
                roleName: 'Admin',
            },
        ]);
        const stored = storage.getItem(ACCOUNTS_KEY) ?? '';
        expect(JSON.parse(stored)).toMatchObject({ format: ACCOUNTS_FORMAT });
        expect(stored).not.toContain('@');
    });

    it('ein anderes Konto derselben Stadt ersetzt den Eintrag', () => {
        const storage = new MemoryStorage();
        rememberAccount(storage, account('test', 'Feuerwehr Test', 7));
        rememberAccount(storage, account('test', 'Feuerwehr Test', 8, 'Benutzer'));

        expect(readAccounts(storage)).toEqual([
            {
                slug: 'test',
                tenantName: 'Feuerwehr Test',
                accountId: 8,
                displayName: 'Anna',
                roleName: 'Benutzer',
            },
        ]);
    });

    it('Super-Admin (ohne Stadt) kommt nicht in die Liste', () => {
        const storage = new MemoryStorage();
        rememberAccount(storage, { ...account('test', 'x'), tenant: null });
        expect(readAccounts(storage)).toEqual([]);
    });

    it('Abmelden entfernt nur den Eintrag dieser Stadt', () => {
        const storage = new MemoryStorage();
        rememberAccount(storage, account('test', 'Feuerwehr Test'));
        rememberAccount(storage, account('hattingen', 'FF Hattingen'));

        forgetAccount(storage, 'test');

        expect(readAccounts(storage).map((entry) => entry.slug)).toEqual(['hattingen']);
    });

    it('unlesbarer, fremder oder neuerer Eintrag gilt als leer', () => {
        const storage = new MemoryStorage();
        for (const value of [
            'kaputt',
            '[]',
            JSON.stringify({ format: ACCOUNTS_FORMAT + 1, accounts: [] }),
        ]) {
            storage.setItem(ACCOUNTS_KEY, value);
            expect(readAccounts(storage)).toEqual([]);
        }
        storage.setItem(
            ACCOUNTS_KEY,
            JSON.stringify({
                format: ACCOUNTS_FORMAT,
                accounts: [
                    {
                        slug: 'service',
                        tenantName: 'x',
                        accountId: 1,
                        displayName: 'x',
                        roleName: null,
                    },
                    {
                        slug: 'Test',
                        tenantName: 'x',
                        accountId: 1,
                        displayName: 'x',
                        roleName: null,
                    },
                    {
                        slug: 'test',
                        tenantName: 'Feuerwehr Test',
                        accountId: 1,
                        displayName: 'x',
                        roleName: null,
                    },
                ],
            }),
        );
        expect(readAccounts(storage).map((entry) => entry.slug)).toEqual(['test']);
    });

    it('ohne Speicher keine Liste und kein Fehler', () => {
        expect(readAccounts(null)).toEqual([]);
        expect(() => {
            rememberAccount(null, account('test', 'Feuerwehr Test'));
        }).not.toThrow();
    });
});

describe('Einträge mit Kennzeichen', () => {
    it('aktuell, angemeldet (gespeicherte Sitzung desselben Kontos) oder neu anmelden', async () => {
        const storage = new MemoryStorage();
        rememberAccount(storage, account('test', 'Feuerwehr Test', 7));
        rememberAccount(storage, account('hattingen', 'FF Hattingen', 9));
        rememberAccount(storage, account('tuttlingen', 'FF Tuttlingen', 11));
        storeSession(storage, 'hattingen', 9);
        storeSession(storage, 'tuttlingen', 12);
        storage.setItem(
            'ff-tenant:hattingen',
            JSON.stringify({ name: 'FF Hattingen', accent_color: '#1d3557' }),
        );

        const entries = await accountEntries(storage, new MemoryDb(), 'test');

        expect(entries.map((entry) => [entry.slug, entry.state, entry.accentColor])).toEqual([
            ['test', 'current', null],
            ['hattingen', 'signed-in', '#1d3557'],
            ['tuttlingen', 'expired', null],
        ]);
    });

    it('zählt nicht gesendete Buchungen des Kontos der anderen Stadt', async () => {
        const storage = new MemoryStorage();
        const db = new MemoryDb();
        rememberAccount(storage, account('hattingen', 'FF Hattingen', 9));
        storeSession(storage, 'hattingen', 9);
        const base = {
            deviceId: 4,
            identifier: 'M4',
            deviceTypeName: 'Atemschutzmaske',
            version: 3,
            occurredAt: '2026-11-06T08:00:00Z',
        };
        await db.put(
            'queue',
            'hattingen:9',
            storedQueue([
                { ...base, clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c21', state: 'waiting' },
                { ...base, clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c22', state: 'stale' },
                { ...base, clientRef: '0b4d2a7c-1f3e-4c5a-9b8d-7e6f5a4b3c23', state: 'done' },
            ]),
        );
        await db.put(
            'queue',
            'hattingen:10',
            storedQueue([{ ...base, clientRef: 'x', state: 'waiting' }]),
        );

        const [entry] = await accountEntries(storage, db, 'test');

        expect(entry?.unsent).toBe(2);
        expect(entry === undefined ? [] : accountHints(entry)).toEqual([
            '2 nicht gesendete Buchungen – werden nach dem Wechsel gesendet.',
        ]);
    });

    it('Hinweise und Kennzeichen', () => {
        const entry = {
            slug: 'tuttlingen',
            tenantName: 'FF Tuttlingen',
            accountId: 11,
            displayName: 'Anna',
            roleName: 'Gerätewart',
            accentColor: null,
        };
        expect(accountHints({ ...entry, state: 'expired', unsent: 1 })).toEqual([
            'Anmeldung abgelaufen – beim Antippen öffnet sich die Anmeldung dieser Stadt.',
            '1 nicht gesendete Buchung – wird nach dem Wechsel gesendet.',
        ]);
        expect(accountHints({ ...entry, state: 'current', unsent: 3 })).toEqual([]);
        expect(accountStateLabel('current')).toBe('aktuell');
        expect(accountStateLabel('signed-in')).toBe('angemeldet');
        expect(accountStateLabel('signed-in', true)).toBe('wechseln');
        expect(accountStateLabel('expired', true)).toBe('neu anmelden');
    });

    it('Kürzel im Symbol ohne allgemeine Wörter', () => {
        expect(accountInitials('FF Immendingen')).toBe('IM');
        expect(accountInitials('Feuerwehr Test')).toBe('TE');
        expect(accountInitials('Freiwillige Feuerwehr Bad Dürrheim')).toBe('BA');
        expect(accountInitials('Stadt')).toBe('ST');
    });
});

describe('Weitere Stadt hinzufügen', () => {
    it('Kürzel oder Adresse', () => {
        expect(cityFromInput(' hattingen ', 'test')).toEqual({ slug: 'hattingen' });
        expect(cityFromInput('www.prodad.de/firefighting/geisingen/', 'test')).toEqual({
            slug: 'geisingen',
        });
        expect(
            cityFromInput('https://www.prodad.de/firefighting/bad-duerrheim/anmelden', 'test'),
        ).toEqual({
            slug: 'bad-duerrheim',
        });
    });

    it('Prüfung wie TenantSlug, eigene Stadt und leere Eingabe', () => {
        expect(cityFromInput('', 'test')).toEqual({
            problem: 'Bitte die Adresse oder das Kürzel der Stadt eingeben.',
        });
        expect(cityFromInput('test', 'test')).toEqual({
            problem: 'In dieser Stadt sind Sie gerade angemeldet.',
        });
        for (const input of ['service', 'Hattingen', 'a--b', 'www.prodad.de/firefighting//']) {
            expect(cityFromInput(input, 'test')).toHaveProperty('problem');
        }
    });

    it('Adresse relativ zum Basispfad', () => {
        const base = 'https://www.prodad.de/firefighting/test/';
        expect(cityUrl('hattingen', '', base)).toBe(
            'https://www.prodad.de/firefighting/hattingen/',
        );
        expect(cityUrl('hattingen', 'anmelden', base)).toBe(
            'https://www.prodad.de/firefighting/hattingen/anmelden',
        );
    });
});
