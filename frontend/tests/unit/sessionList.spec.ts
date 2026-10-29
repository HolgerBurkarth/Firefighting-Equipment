import { describe, expect, it } from 'vitest';
import type { AccountSession } from '../../src/api/auth';
import {
    endedSessionsText,
    sessionLabel,
    sessionTimeText,
    sortSessions,
} from '../../src/auth/sessionList';

/** 29.10.2026 14:30 Ortszeit; alle Zeiten in Ortszeit gebaut, daher unabhängig von der Zeitzone. */
const NOW = new Date(2026, 9, 29, 14, 30);

function session(id: number, overrides: Partial<AccountSession> = {}): AccountSession {
    return {
        id,
        deviceLabel: 'Chrome auf Android',
        createdAt: new Date(2026, 9, 12, 9, 15).toISOString().replace(/\.\d{3}Z$/, 'Z'),
        lastSeenAt: new Date(2026, 9, 29, 14, 25).toISOString().replace(/\.\d{3}Z$/, 'Z'),
        current: false,
        ...overrides,
    };
}

describe('sortSessions', () => {
    it('dieses Gerät zuerst, sonst Reihenfolge des Servers', () => {
        const list = [session(1), session(2, { current: true }), session(3)];
        expect(sortSessions(list).map((s) => s.id)).toEqual([2, 1, 3]);
    });
});

describe('sessionLabel', () => {
    it('ohne Bezeichnung „Unbekanntes Gerät“', () => {
        expect(sessionLabel(session(1))).toBe('Chrome auf Android');
        expect(sessionLabel(session(1, { deviceLabel: null }))).toBe('Unbekanntes Gerät');
    });
});

describe('sessionTimeText', () => {
    it('andere Geräte: zuletzt aktiv und angemeldet', () => {
        expect(sessionTimeText(session(1), NOW)).toBe(
            'Zuletzt aktiv vor 5 Min. · angemeldet 12.10.2026',
        );
    });

    it('dieses Gerät: nur die Anmeldung', () => {
        const own = session(1, {
            current: true,
            createdAt: new Date(2026, 9, 29, 8, 5).toISOString().replace(/\.\d{3}Z$/, 'Z'),
        });
        expect(sessionTimeText(own, NOW)).toBe('Angemeldet heute 08:05');
    });
});

describe('endedSessionsText', () => {
    it.each([
        [0, 'Es war kein anderes Gerät angemeldet.'],
        [1, '1 Gerät wurde abgemeldet.'],
        [3, '3 Geräte wurden abgemeldet.'],
    ])('%i', (count, text) => {
        expect(endedSessionsText(count)).toBe(text);
    });
});
