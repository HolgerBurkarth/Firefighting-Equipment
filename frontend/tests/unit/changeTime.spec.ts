import { describe, expect, it } from 'vitest';
import { isUtcTime } from '../../src/api/devices';
import { changeTimeText } from '../../src/data/changeTime';

/** Ortszeit des Rechners als UTC-Text wie vom Server (Tests unabhängig von der Zeitzone). */
function utc(year: number, month: number, day: number, hour: number, minute: number): string {
    return new Date(year, month - 1, day, hour, minute).toISOString().replace('.000Z', 'Z');
}

const NOW = new Date(2026, 9, 29, 14, 30, 0);

describe('changeTimeText', () => {
    it.each([
        ['genau 1 Minute', utc(2026, 10, 29, 14, 29), 'vor 1 Min.'],
        ['5 Minuten', utc(2026, 10, 29, 14, 25), 'vor 5 Min.'],
        ['59 Minuten', utc(2026, 10, 29, 13, 31), 'vor 59 Min.'],
        ['genau 1 Stunde', utc(2026, 10, 29, 13, 30), 'heute 13:30'],
        ['heute früh', utc(2026, 10, 29, 0, 2), 'heute 00:02'],
        ['gestern', utc(2026, 10, 28, 18, 30), 'gestern 18:30'],
        ['gestern kurz vor Mitternacht', utc(2026, 10, 28, 23, 59), 'gestern 23:59'],
        ['vorgestern', utc(2026, 10, 27, 23, 59), '27.10.2026'],
        ['älter', utc(2026, 10, 12, 9, 0), '12.10.2026'],
        ['Vorjahr', utc(2025, 1, 3, 9, 0), '03.01.2025'],
    ])('%s', (_label, time, expected) => {
        expect(changeTimeText(time, NOW)).toBe(expected);
    });

    it('unter 1 Minute und leicht in der Zukunft: gerade eben', () => {
        const now = new Date(2026, 9, 29, 14, 30, 30);
        expect(changeTimeText(utc(2026, 10, 29, 14, 30), now)).toBe('gerade eben');
        expect(changeTimeText(utc(2026, 10, 29, 14, 40), now)).toBe('gerade eben');
    });

    it('gestern über den Monatswechsel', () => {
        expect(changeTimeText(utc(2026, 10, 31, 20, 5), new Date(2026, 10, 1, 8, 0))).toBe(
            'gestern 20:05',
        );
    });

    it('rechnet in der Ortszeit (UTC-Text vom Server)', () => {
        const time = '2026-10-29T12:02:00Z';
        const local = new Date(time);
        const expected = `heute ${String(local.getHours()).padStart(2, '0')}:02`;
        const now = new Date(local.getFullYear(), local.getMonth(), local.getDate(), 23, 0);
        expect(changeTimeText(time, now)).toBe(expected);
    });
});

describe('isUtcTime', () => {
    it.each([
        ['2026-10-12T08:15:07Z', true],
        ['2026-10-12T08:15:07', false],
        ['2026-10-12T08:15:07.123Z', false],
        ['2026-13-45T99:00:00Z', false],
        ['', false],
        [null, false],
        [12, false],
    ])('%s → %s', (value, expected) => {
        expect(isUtcTime(value)).toBe(expected);
    });
});
