import { describe, expect, it } from 'vitest';
import {
    DEFAULT_MEASURE_RUNS,
    DEFAULT_MEASURE_URL,
    median,
    parseMeasureArgs,
    summaryLine,
} from '../e2e/support/startTiming';

describe('parseMeasureArgs', () => {
    it('uses the test city and five runs by default', () => {
        expect(parseMeasureArgs([])).toEqual({
            url: DEFAULT_MEASURE_URL,
            runs: DEFAULT_MEASURE_RUNS,
            slow: false,
        });
    });

    it('reads url, runs and slow', () => {
        expect(
            parseMeasureArgs([
                '--url=http://localhost:8000/firefighting/test/',
                '--runs=3',
                '--slow',
            ]),
        ).toEqual({ url: 'http://localhost:8000/firefighting/test/', runs: 3, slow: true });
    });

    it('refuses other cities, bad run counts and unknown arguments', () => {
        expect(parseMeasureArgs(['--url=https://www.prodad.de/firefighting/immendingen/'])).toBe(
            '--url nur für die Test-Stadt (…/firefighting/test/).',
        );
        expect(parseMeasureArgs(['--url=kein url'])).toBe('--url erwartet eine Adresse.');
        expect(parseMeasureArgs(['--runs=0'])).toBe(
            '--runs=N erwartet eine Ganzzahl von 1 bis 50.',
        );
        expect(parseMeasureArgs(['--runs=2.5'])).toBe(
            '--runs=N erwartet eine Ganzzahl von 1 bis 50.',
        );
        expect(parseMeasureArgs(['--fast'])).toBe('Unbekanntes Argument: --fast');
    });
});

describe('median and summaryLine', () => {
    it('takes the middle value or the mean of the two middle ones', () => {
        expect(median([])).toBeNull();
        expect(median([3, 1, 2])).toBe(2);
        expect(median([4, 1, 3, 2])).toBe(2.5);
    });

    it('formats seconds in German', () => {
        expect(summaryLine('Erster Besuch', [4100, 4520, 5030])).toBe(
            'Erster Besuch: 4,52 s (Median aus 3; 4,10–5,03 s)',
        );
        expect(summaryLine('Erster Besuch', [])).toBe('Erster Besuch: keine Messung');
    });
});
