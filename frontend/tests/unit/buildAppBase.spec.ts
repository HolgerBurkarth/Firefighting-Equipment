// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { BASE_PLACEHOLDER, injectBase } from '../../build/appBase';

const indexHtml = readFileSync(new URL('../../index.html', import.meta.url), 'utf8');

describe('Basispfad in index.html', () => {
    it('enthält den Platzhalter genau einmal in <base href>', () => {
        expect(indexHtml.split(BASE_PLACEHOLDER)).toHaveLength(2);
        expect(indexHtml).toContain(`<base href="${BASE_PLACEHOLDER}" />`);
    });

    it('setzt den Bereich ein', () => {
        expect(injectBase(indexHtml, 'immendingen')).toContain(
            '<base href="/firefighting/immendingen/" />',
        );
    });

    it('bricht ohne Platzhalter ab', () => {
        expect(() => injectBase('<html></html>', 'test')).toThrow(/Platzhalter/);
    });

    it('enthält kein Inline-Skript und keinen Inline-Stil (CSP)', () => {
        expect(indexHtml).not.toMatch(/<script(?![^>]*\bsrc=)[^>]*>/);
        expect(indexHtml).not.toMatch(/<style|\sstyle=/);
    });
});
