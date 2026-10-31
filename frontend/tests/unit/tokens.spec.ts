// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { DEFAULT_ACCENT } from '../../src/runtime/accent';

const css = readFileSync(new URL('../../src/styles/tokens.css', import.meta.url), 'utf8');
const indexHtml = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');

/** Deklarationen des ersten Blocks nach dem Selektor (ohne verschachtelte Blöcke). */
function block(selector: string): Map<string, string> {
    const start = css.indexOf(`${selector} {`);
    expect(start, `Block ${selector} fehlt`).toBeGreaterThanOrEqual(0);
    const body = css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start));
    const withoutComments = body.replace(/\/\*[\s\S]*?\*\//g, '');
    const entries = [...withoutComments.matchAll(/(--[a-z0-9-]+|color-scheme)\s*:\s*([^;]+);/g)];
    return new Map(entries.map((m) => [m[1] ?? '', (m[2] ?? '').trim()]));
}

const light = block(':root');
const darkForced = block(":root[data-theme='dark']");
const darkSystem = block(":root:not([data-theme='light'])");

function hex(tokens: Map<string, string>, name: string): string {
    const value = tokens.get(name) ?? light.get(name);
    expect(value, `${name} fehlt`).toMatch(/^#[0-9a-f]{6}$/);
    return value ?? '';
}

function luminance(color: string): number {
    const [r = 0, g = 0, b = 0] = [1, 3, 5].map((i) => {
        const c = parseInt(color.slice(i, i + 2), 16) / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: string, b: string): number {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return ((hi ?? 0) + 0.05) / ((lo ?? 0) + 0.05);
}

describe('Design-Variablen', () => {
    it('Dunkel erzwungen und Dunkel per System sind gleich', () => {
        expect([...darkSystem]).toEqual([...darkForced]);
    });

    it('Dunkel überschreibt nur Variablen, die es hell gibt', () => {
        for (const name of darkForced.keys()) {
            expect(light.has(name), name).toBe(true);
        }
    });

    it('Standard-Akzentfarbe ist Feuerwehrrot wie im Backend und in index.html', () => {
        expect(light.get('--accent-default')).toBe(DEFAULT_ACCENT);
        expect(indexHtml).toContain(`<meta name="theme-color" content="${DEFAULT_ACCENT}" />`);
        expect(contrast(DEFAULT_ACCENT, hex(light, '--on-accent'))).toBeGreaterThanOrEqual(4.5);
    });

    it('Papier (QR-Code, Aushang) ist in beiden Modi gleich und gut lesbar', () => {
        for (const name of ['--paper', '--ink', '--ink-muted']) {
            expect(darkForced.has(name), name).toBe(false);
        }
        expect(contrast(hex(light, '--ink'), hex(light, '--paper'))).toBeGreaterThanOrEqual(15);
        expect(contrast(hex(light, '--ink-muted'), hex(light, '--paper'))).toBeGreaterThanOrEqual(
            4.5,
        );
    });

    it('übernimmt die Farben des Mockups', () => {
        expect(light.get('--bg')).toBe('#f3f4f6');
        expect(light.get('--text')).toBe('#1b2430');
        expect(darkForced.get('--bg')).toBe('#12161c');
        expect(darkForced.get('--text')).toBe('#e7ecf2');
        expect(light.get('--status-ready-fg')).toBe('#1b6330');
        expect(light.get('--status-maintenance-bg')).toBe('#fff1db');
        expect(light.get('--status-defective-fg')).toBe('#9b1c1c');
    });

    describe.each([
        ['hell', light],
        ['dunkel', darkForced],
    ])('Kontrast %s (mindestens 4,5:1)', (_mode, tokens) => {
        it.each(['ready', 'maintenance', 'defective', 'retired'])('Status %s', (status) => {
            const fg = hex(tokens, `--status-${status}-fg`);
            const bg = hex(tokens, `--status-${status}-bg`);
            expect(contrast(fg, bg)).toBeGreaterThanOrEqual(4.5);
        });

        it.each(['--text', '--text-secondary', '--muted'])(
            '%s auf Hintergrund und Fläche',
            (name) => {
                expect(contrast(hex(tokens, name), hex(tokens, '--bg'))).toBeGreaterThanOrEqual(
                    4.5,
                );
                expect(
                    contrast(hex(tokens, name), hex(tokens, '--surface')),
                ).toBeGreaterThanOrEqual(4.5);
            },
        );
    });
});
