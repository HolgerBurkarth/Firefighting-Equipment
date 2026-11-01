import { describe, expect, it } from 'vitest';
import { revealOffset } from '../../src/runtime/reveal';

describe('revealOffset (T3)', () => {
    // Sichtbarer Bereich: unter der festen Kopfzeile (60) bis zum unteren Rand (500).
    const view = { top: 60, bottom: 500 };

    it('ganz sichtbar: kein Scrollen', () => {
        expect(revealOffset({ top: 100, bottom: 500 }, view)).toBe(0);
        expect(revealOffset({ top: 60, bottom: 300 }, view)).toBe(0);
    });

    it('läuft unten aus dem Bild: so weit, dass das Ende sichtbar wird', () => {
        expect(revealOffset({ top: 400, bottom: 650 }, view)).toBe(150);
    });

    it('höher als der sichtbare Bereich: der Kopf bleibt oben sichtbar', () => {
        expect(revealOffset({ top: 400, bottom: 1000 }, view)).toBe(340);
    });

    it('Kopf schon unter der Kopfzeile: nie nach oben scrollen', () => {
        expect(revealOffset({ top: 40, bottom: 900 }, view)).toBe(0);
    });
});
