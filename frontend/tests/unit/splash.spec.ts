import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import {
    FIRST_PAGE_MARK,
    markStartup,
    readRememberedTenant,
    rememberTenant,
    rememberedTenantKey,
    removeSplash,
    setSplashStep,
    showRememberedTenant,
    SPLASH_STEPS,
} from '../../src/runtime/splash';

// Pfade ohne URL-Objekt: unter happy-dom ist URL die Klasse des Browsers, nicht die von Node.
const FRONTEND = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const INDEX_HTML = readFileSync(join(FRONTEND, 'index.html'), 'utf8');
const SPLASH_CSS = readFileSync(join(FRONTEND, 'src', 'styles', 'splash.css'), 'utf8');

class MemoryStorage {
    readonly items = new Map<string, string>();
    getItem(key: string): string | null {
        return this.items.get(key) ?? null;
    }
    setItem(key: string, value: string): void {
        this.items.set(key, value);
    }
    removeItem(key: string): void {
        this.items.delete(key);
    }
}

/** Startbild wie in index.html (der Teil zwischen den Kommentaren und #app). */
function mountSplash(): void {
    const match = /<div id="splash"[\s\S]*?\n {8}<\/div>/.exec(INDEX_HTML);
    if (match === null) {
        throw new Error('Startbild fehlt in index.html');
    }
    document.body.innerHTML = match[0];
}

beforeEach(() => {
    mountSplash();
    document.documentElement.style.removeProperty('--accent');
    performance.clearMarks();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Startbild in index.html (Plan Phase 1, Schritt 1.7d)', () => {
    it('steht vor #app, ohne Inline-Stil und ohne Skript', () => {
        expect(INDEX_HTML.indexOf('id="splash"')).toBeLessThan(INDEX_HTML.indexOf('id="app"'));
        expect(INDEX_HTML).not.toMatch(
            /style=|<style|<script(?![^>]*type="module" src="\/src\/main\.ts")/,
        );
        const splash = document.getElementById('splash');
        expect(splash?.dataset['step']).toBe('start');
        expect(splash?.querySelector('[data-splash-title]')?.textContent).toBe('Feuerwehr');
        expect(splash?.querySelector('[data-splash-label]')?.textContent).toBe(
            'App wird geladen …',
        );
    });

    it('erscheint erst nach 0,4 s (nur per CSS), dunkle Blöcke gleich', () => {
        expect(SPLASH_CSS).toMatch(/animation: splash-show 0\.2s ease-out 0\.4s forwards;/);
        const blocks = [...SPLASH_CSS.matchAll(/\.splash \{\n((?: {8}| {4})--splash[^}]*)\}/g)].map(
            (match) => (match[1] ?? '').replace(/\s+/g, ' ').trim(),
        );
        // Hell, dunkel (data-theme) und dunkel (System).
        expect(blocks).toHaveLength(3);
        expect(blocks[1]).toBe(blocks[2]);
        expect(blocks[1]).toContain('splash-dark.svg');
        expect(SPLASH_CSS).toContain('prefers-reduced-motion: reduce');
    });
});

describe('setSplashStep und removeSplash', () => {
    it('Schritte mit Attribut, Text und aria-valuenow', () => {
        const splash = document.getElementById('splash');
        const bar = splash?.querySelector('[role="progressbar"]');
        expect(bar?.hasAttribute('aria-valuenow')).toBe(false);
        for (const step of ['app', 'session', 'data'] as const) {
            setSplashStep(document, step);
            expect(splash?.dataset['step']).toBe(step);
            expect(bar?.getAttribute('aria-valuenow')).toBe(String(SPLASH_STEPS[step].value));
            expect(splash?.querySelector('[data-splash-label]')?.textContent).toBe(
                SPLASH_STEPS[step].label,
            );
        }
        expect(SPLASH_STEPS.session.label).toBe('Anmeldung wird geprüft …');
        expect(SPLASH_STEPS.data.label).toBe('Ausrüstung wird geladen …');
    });

    it('entfernt das Startbild einmal und setzt die Marke „erste Seite“', () => {
        removeSplash(document);
        removeSplash(document);
        expect(document.getElementById('splash')).toBeNull();
        expect(performance.getEntriesByName(FIRST_PAGE_MARK, 'mark')).toHaveLength(1);
        expect(() => {
            setSplashStep(document, 'data');
        }).not.toThrow();
    });

    it('markStartup setzt jede Marke nur einmal', () => {
        markStartup('ff:x');
        markStartup('ff:x');
        expect(performance.getEntriesByName('ff:x', 'mark')).toHaveLength(1);
    });
});

describe('Stadt aus dem letzten Besuch', () => {
    it('merkt sich Name und Farbe je Bereich und zeigt sie beim nächsten Start', () => {
        const storage = new MemoryStorage();
        rememberTenant(storage, 'test', { name: 'FF Immendingen', accentColor: '#1565c0' });
        expect(readRememberedTenant(storage, 'test')).toEqual({
            name: 'FF Immendingen',
            accentColor: '#1565c0',
        });
        expect(readRememberedTenant(storage, 'andere')).toBeNull();
        showRememberedTenant(document, storage, 'test', false);
        expect(document.querySelector('[data-splash-title]')?.textContent).toBe('FF Immendingen');
        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('#1565c0');
    });

    it('erster Besuch: „Feuerwehr“ in der Standardfarbe; unter service „Super-Admin“', () => {
        const storage = new MemoryStorage();
        showRememberedTenant(document, storage, 'test', false);
        expect(document.querySelector('[data-splash-title]')?.textContent).toBe('Feuerwehr');
        expect(document.documentElement.style.getPropertyValue('--accent')).toBe('');
        showRememberedTenant(document, storage, 'service', true);
        expect(document.querySelector('[data-splash-title]')?.textContent).toBe('Super-Admin');
    });

    it('vergisst sie mit null; unlesbare oder falsche Werte zählen nicht, Speicherfehler auch nicht', () => {
        const storage = new MemoryStorage();
        rememberTenant(storage, 'test', { name: 'X', accentColor: null });
        rememberTenant(storage, 'test', null);
        expect(storage.getItem(rememberedTenantKey('test'))).toBeNull();
        for (const value of ['{kaputt', '[]', '{"name":""}', '{"name":"X","accent_color":5}']) {
            storage.setItem(rememberedTenantKey('test'), value);
            expect(readRememberedTenant(storage, 'test')).toBeNull();
        }
        const throwing = new MemoryStorage();
        throwing.setItem = () => {
            throw new Error('QuotaExceededError');
        };
        expect(() => {
            rememberTenant(throwing, 'test', { name: 'X', accentColor: null });
        }).not.toThrow();
        expect(readRememberedTenant(null, 'test')).toBeNull();
    });
});
