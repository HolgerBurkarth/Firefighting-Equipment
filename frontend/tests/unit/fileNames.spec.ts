// @vitest-environment node
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { sanitizeFileName } from '../../build/fileNames';

const assetsPhp = readFileSync(
    new URL('../../../src/Http/Frontend/FrontendAssets.php', import.meta.url),
    'utf8',
);
const phpPattern = /FILE_NAME_PATTERN = '\/(.+)\/';/.exec(assetsPhp)?.[1] ?? '';
// PCRE-Ende \z wird in JavaScript zu $ (ohne Flag m gleichbedeutend).
const allowed = new RegExp(phpPattern.replace(/\\z$/, '$'));

describe('sanitizeFileName', () => {
    it.each([
        ['_plugin-vue_export-helper', 'plugin-vue_export-helper'],
        ['index', 'index'],
        ['LoginPage', 'LoginPage'],
        ['.versteckt', 'versteckt'],
        ['a b/c?d', 'a_b_c_d'],
        ['___', 'chunk'],
    ])('%j → %j', (name, expected) => {
        expect(sanitizeFileName(name)).toBe(expected);
    });

    it('Ergebnis passt (mit Hash und Endung) zur Regel in FrontendAssets.php', () => {
        expect(phpPattern).not.toBe('');
        for (const name of ['_plugin-vue_export-helper', '.x', 'ä-ö', 'index']) {
            expect(allowed.test(`${sanitizeFileName(name)}-CStXJJQD.js`)).toBe(true);
        }
    });
});
