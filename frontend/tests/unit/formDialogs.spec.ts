// @vitest-environment node
import { readFileSync, readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Jedes Fenster mit Eingaben läuft über FormDialog und meldet ihm seine ungespeicherten Eingaben
 * (`:unsaved`): Nur dann schließt ein Klick daneben es nicht und Escape fragt nach (Live-Test
 * 31.10.2026: ein Klick auf den Update-Hinweis verwarf die Eingaben ohne Rückfrage).
 */
const src = fileURLToPath(new URL('../../src/', import.meta.url));

function vueFiles(dir: string): string[] {
    return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            return vueFiles(path);
        }
        return entry.name.endsWith('.vue') ? [path] : [];
    });
}

const files = vueFiles(src).map((path) => ({
    name: relative(src, path).replace(/\\/g, '/'),
    text: readFileSync(path, 'utf8'),
}));

describe('Fenster mit Eingaben', () => {
    const usages = files.flatMap((file) =>
        [...file.text.matchAll(/<FormDialog\b[^>]*>/g)].map((match) => ({
            name: file.name,
            tag: match[0],
        })),
    );

    it('es gibt sie (Suche funktioniert)', () => {
        expect(usages.length).toBeGreaterThanOrEqual(11);
    });

    it.each(usages)('$name übergibt FormDialog seine ungespeicherten Eingaben', ({ tag }) => {
        expect(tag).toMatch(/\s:unsaved="/);
    });

    it('nur FormDialog, ConfirmDialog und das Menü öffnen eigene Fenster (Reka UI)', () => {
        const own = files
            .filter((file) => /<(DialogRoot|AlertDialogRoot)\b/.test(file.text))
            .map((file) => file.name)
            .sort();
        expect(own).toEqual([
            'components/AdminMenu.vue',
            'components/ConfirmDialog.vue',
            'components/FormDialog.vue',
        ]);
    });
});
