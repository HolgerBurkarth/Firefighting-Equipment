import { describe, expect, it } from 'vitest';
import {
    ALL_LIMIT_BYTES,
    judge,
    PAGE_LIMIT_BYTES,
    RISE_MIN_BYTES,
    SILENCE_MS,
} from '../live/support/memoryGuard';
import { cardTextPattern } from '../live/support/guard';

const MB = 1024 * 1024;

describe('Schutz des Live-Laufs (memoryGuard)', () => {
    it('lässt einen ruhigen Verlauf zu', () => {
        expect(judge([5 * MB, 6 * MB, 5 * MB], 300 * MB, 500)).toBe('ok');
    });

    it('bricht über 1 GB Seitenspeicher ab', () => {
        expect(judge([PAGE_LIMIT_BYTES + 1], null, 0)).toBe('page-limit');
    });

    it('bricht über 3 GB aller Prozesse ab', () => {
        expect(judge([5 * MB], ALL_LIMIT_BYTES + 1, 0)).toBe('all-limit');
    });

    it('bricht ab, wenn die Seite 10 s nicht antwortet', () => {
        expect(judge([5 * MB], null, SILENCE_MS + 1)).toBe('silent');
    });

    it('bricht ab, wenn der Speicher 10 s lang ohne Pause um mindestens 100 MB wächst', () => {
        const rising = Array.from({ length: 22 }, (_, i) => 10 * MB + (i * RISE_MIN_BYTES) / 20);
        expect(judge(rising, null, 0)).toBe('rising');
    });

    it('beachtet kleine oder unterbrochene Anstiege nicht', () => {
        const small = Array.from({ length: 22 }, (_, i) => 10 * MB + i * 1024);
        expect(judge(small, null, 0)).toBe('ok');
        const dropped = Array.from({ length: 22 }, (_, i) => 10 * MB + i * 10 * MB);
        dropped[11] = 5 * MB;
        expect(judge(dropped, null, 0)).toBe('ok');
    });
});

describe('Muster für die Karte in der Trefferliste', () => {
    it('trifft die zusammengesetzte Nummer, nicht M9 für M94', () => {
        expect(cardTextPattern('M94').test('M94Live-Testtyp 2Lager Gerätehaus')).toBe(true);
        expect(cardTextPattern('M9').test('M94Live-Testtyp 2')).toBe(false);
        expect(cardTextPattern('M9').test('M9Live-Testtyp 2')).toBe(true);
    });
});
