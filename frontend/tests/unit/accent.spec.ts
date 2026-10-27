import { beforeEach, describe, expect, it } from 'vitest';
import { applyAccent, DEFAULT_ACCENT } from '../../src/runtime/accent';

function themeColor(): string | null | undefined {
    return document.querySelector('meta[name="theme-color"]')?.getAttribute('content');
}

describe('applyAccent', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="theme-color" content="#b71c1c">';
        document.documentElement.removeAttribute('style');
    });

    it('setzt die Stadtfarbe als --accent und theme-color', () => {
        expect(applyAccent(document.documentElement, '#1d3557')).toBe('#1d3557');
        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#1d3557');
        expect(themeColor()).toBe('#1d3557');
    });

    it.each([null, '', 'red', '#ABC', '#1D3557', '#1d35577'])(
        'fällt bei %s auf die Standardfarbe zurück',
        (color) => {
            document.documentElement.style.setProperty('--accent', '#123456');
            expect(applyAccent(document.documentElement, color)).toBe(DEFAULT_ACCENT);
            expect(document.documentElement.style.getPropertyValue('--accent')).toBe('');
            expect(themeColor()).toBe(DEFAULT_ACCENT);
        },
    );

    it('verwendet als Standard Feuerwehrrot wie AccentColor::DEFAULT', () => {
        expect(DEFAULT_ACCENT).toBe('#b71c1c');
    });
});
