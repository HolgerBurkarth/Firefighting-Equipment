// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import {
    ACCENT_SWATCHES,
    accentRequest,
    accentSelection,
    checkAccent,
    contrastText,
    contrastWithWhite,
    LOGO_EDGE,
    LOGO_MARGIN,
    logoSizeProblem,
    logoSourceProblem,
    MAX_LOGO_BYTES,
    MIN_CONTRAST,
    parseAccent,
    placeLogo,
} from '../../src/data/appearance';
import { DEFAULT_ACCENT } from '../../src/runtime/accent';

function php(file: string): string {
    return readFileSync(new URL(`../../../src/${file}`, import.meta.url), 'utf8');
}

function intConstant(file: string, name: string): number {
    const match = new RegExp(`const int ${name} = ([0-9_]+);`).exec(php(file));
    return Number(match?.[1]?.replaceAll('_', ''));
}

describe('Akzentfarbe wie AccentColor in PHP', () => {
    it('Mindestkontrast und Standardfarbe wie im Backend', () => {
        expect(php('Tenant/AccentColor.php')).toContain(
            `const float MIN_CONTRAST = ${String(MIN_CONTRAST)};`,
        );
        expect(php('Tenant/AccentColor.php')).toContain(
            `const string DEFAULT = '${DEFAULT_ACCENT}';`,
        );
    });

    it.each([
        ['#B71C1C', '#b71c1c'],
        ['  #1d3557\n', '#1d3557'],
        ['#a33', '#aa3333'],
        ['#ABC', '#aabbcc'],
    ])('liest %j als %s', (input, expected) => {
        expect(parseAccent(input)).toBe(expected);
    });

    it.each(['', 'b71c1c', '#b71c1', '#b71c1cc', '#ggg', 'rot', '#b71c1c;', ' #b71c1c'])(
        'lehnt %j ab',
        (input) => {
            expect(parseAccent(input)).toBeNull();
            const check = checkAccent(input);
            expect(check.ok).toBe(false);
            expect(!check.ok && check.message).toBe(
                'Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).',
            );
        },
    );

    it('Kontrast nach WCAG 2.x mit denselben Grenzfällen wie AccentColorTest', () => {
        expect(contrastWithWhite('#ec1400')).toBeGreaterThanOrEqual(4.5);
        expect(contrastWithWhite('#ec1400')).toBeCloseTo(4.50003, 4);
        expect(contrastWithWhite('#ea1c05')).toBeLessThan(4.5);
        expect(contrastWithWhite('#ea1c05')).toBeCloseTo(4.49989, 4);
        expect(contrastWithWhite('#b71c1c')).toBeCloseTo(6.57, 2);
        expect(contrastWithWhite('#000000')).toBeCloseTo(21, 5);
        expect(contrastWithWhite('#ffffff')).toBeCloseTo(1, 5);
        expect(checkAccent('#ec1400').ok).toBe(true);
        expect(checkAccent('#ea1c05').ok).toBe(false);
    });

    it('zeigt den Kontrast abgerundet (nie „4,50“ bei einer abgelehnten Farbe)', () => {
        expect(contrastText(4.49989)).toBe('4,49');
        expect(contrastText(6.5749)).toBe('6,57');
        expect(contrastText(21)).toBe('21,00');
        const check = checkAccent('#e8b44c');
        expect(check.ok).toBe(false);
        expect(!check.ok && check.message).toBe(
            'Zu hell: Weiße Schrift auf #E8B44C erreicht nur einen Kontrast von 1,89:1; nötig ' +
                'sind mindestens 4,5:1. Bitte eine dunklere Farbe wählen.',
        );
        expect(!check.ok && check.color).toBe('#e8b44c');
    });

    it('alle festen Farben sind mit weißer Schrift gut lesbar und verschieden', () => {
        for (const swatch of ACCENT_SWATCHES) {
            expect(contrastWithWhite(swatch.color ?? DEFAULT_ACCENT), swatch.label).toBeGreaterThan(
                MIN_CONTRAST,
            );
            expect(swatch.color === null || parseAccent(swatch.color) === swatch.color).toBe(true);
        }
        expect(ACCENT_SWATCHES[0]?.color).toBeNull();
        expect(new Set(ACCENT_SWATCHES.map((swatch) => swatch.color)).size).toBe(
            ACCENT_SWATCHES.length,
        );
    });

    it('die Standardfarbe gilt als „Standard“ und wird als null gesendet', () => {
        expect(accentSelection(DEFAULT_ACCENT)).toBeNull();
        expect(accentSelection('#1d3557')).toBe('#1d3557');
        expect(accentRequest(null)).toBeNull();
        expect(accentRequest(DEFAULT_ACCENT)).toBeNull();
        expect(accentRequest('#1d3557')).toBe('#1d3557');
    });
});

describe('Logo', () => {
    it('Grenzen wie PngInspector', () => {
        expect(MAX_LOGO_BYTES).toBe(intConstant('Tenant/Logo/PngInspector.php', 'MAX_BYTES'));
        expect(LOGO_EDGE).toBe(intConstant('Tenant/Logo/PngInspector.php', 'MIN_EDGE'));
        expect(LOGO_EDGE).toBe(intConstant('Tenant/Logo/PngInspector.php', 'MAX_EDGE'));
    });

    it('quadratisch: füllt das Quadrat bis auf den Rand', () => {
        const box = LOGO_EDGE - 2 * LOGO_MARGIN;
        expect(placeLogo(1000, 1000)).toEqual({
            x: LOGO_MARGIN,
            y: LOGO_MARGIN,
            width: box,
            height: box,
        });
        expect(placeLogo(16, 16)).toEqual(placeLogo(1000, 1000));
    });

    it('breit bzw. hoch: Seitenverhältnis bleibt, mittig mit Rand', () => {
        expect(placeLogo(960, 480)).toEqual({ x: 16, y: 136, width: 480, height: 240 });
        expect(placeLogo(240, 480)).toEqual({ x: 136, y: 16, width: 240, height: 480 });
    });

    it('ohne Maße (manche SVG) gilt das Bild als quadratisch', () => {
        expect(placeLogo(0, 0)).toEqual(placeLogo(1, 1));
        expect(placeLogo(Number.NaN, 300)).toEqual(placeLogo(300, 300));
        expect(placeLogo(0, 150)).toEqual(placeLogo(1, 1));
    });

    it('Meldungen zur Größe', () => {
        expect(logoSourceProblem(0)).toBe('Die Datei ist leer.');
        expect(logoSourceProblem(10 * 1_048_576)).toBeNull();
        expect(logoSourceProblem(10 * 1_048_576 + 1)).toMatch(/höchstens 10 MB/);
        expect(logoSizeProblem(MAX_LOGO_BYTES)).toBeNull();
        expect(logoSizeProblem(700 * 1024)).toBe(
            'Das Logo wird als PNG zu groß (700 KB, höchstens 500 KB). Bitte ein einfacheres ' +
                'Bild wählen, z. B. ohne Foto im Hintergrund.',
        );
    });
});
