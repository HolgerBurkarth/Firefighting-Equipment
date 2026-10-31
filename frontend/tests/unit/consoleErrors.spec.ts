import { describe, expect, it } from 'vitest';
import { isAbortedFontDownload } from '../e2e/support/consoleErrors';

const ORIGIN = 'http://localhost:4173';
const FONT = `${ORIGIN}/firefighting/test/assets/source-sans-3-latin-600-normal-DeckBYAU.woff2`;

/** Meldung von Firefox wie in CI (31.10.2026, userLayout.spec.ts). */
function message(status: number | string, source: string): string {
    return (
        '[JavaScript Error: "downloadable font: download failed (font-family: "Source Sans 3" ' +
        `style:normal weight:600 width:100 src index:0): status=${String(status)} source: ${source}"]`
    );
}

describe('isAbortedFontDownload (Wächter der Browser-Tests)', () => {
    it('lässt nur den abgebrochenen Download einer Schrift aus assets/ in Firefox zu', () => {
        expect(isAbortedFontDownload(message(2152398850, FONT), 'firefox', ORIGIN)).toBe(true);
        expect(
            isAbortedFontDownload(
                message(2152398850, `${ORIGIN}/firefighting/service/assets/inter-latin.woff`),
                'firefox',
                ORIGIN,
            ),
        ).toBe(true);
        // Ohne Klammern von Playwright (wie in der früheren Ausnahme des Mail-Link-Tests).
        expect(
            isAbortedFontDownload(
                `downloadable font: download failed (font-family: "Inter" style:normal): status=2152398850 source: ${FONT}`,
                'firefox',
                ORIGIN,
            ),
        ).toBe(true);
    });

    it('andere Schriftfehler bleiben Fehler', () => {
        // Anderer Status (z. B. Datei nicht gefunden oder nicht lesbar), auch nur ähnliche Zahlen.
        expect(isAbortedFontDownload(message(2147500037, FONT), 'firefox', ORIGIN)).toBe(false);
        expect(isAbortedFontDownload(message(21523988500, FONT), 'firefox', ORIGIN)).toBe(false);
        expect(isAbortedFontDownload(message('', FONT), 'firefox', ORIGIN)).toBe(false);
        // Andere Browser.
        for (const browser of ['chromium', 'webkit']) {
            expect(isAbortedFontDownload(message(2152398850, FONT), browser, ORIGIN)).toBe(false);
        }
        // Anderer Server (auch anderer Port, z. B. der Update-Testserver eines anderen Tests).
        for (const origin of ['http://localhost:4174', 'https://fonts.gstatic.com']) {
            expect(isAbortedFontDownload(message(2152398850, FONT), 'firefox', origin)).toBe(false);
        }
        expect(
            isAbortedFontDownload(
                message(2152398850, 'https://fonts.gstatic.com/s/sourcesans3/v1/x.woff2'),
                'firefox',
                ORIGIN,
            ),
        ).toBe(false);
        // Nicht aus assets/ des Builds, keine Schrift oder mit Query.
        for (const path of [
            '/firefighting/test/source-sans-3.woff2',
            '/firefighting/test/assets/sub/source-sans-3.woff2',
            '/firefighting/test/assets/../source-sans-3.woff2',
            '/firefighting/test/assets/.hidden.woff2',
            '/firefighting/test/assets/index-abc.js',
            '/firefighting/test/assets/source-sans-3.ttf',
            '/firefighting/test/assets/source-sans-3.woff2?x=1',
            '/other/test/assets/source-sans-3.woff2',
        ]) {
            expect(
                isAbortedFontDownload(message(2152398850, `${ORIGIN}${path}`), 'firefox', ORIGIN),
                path,
            ).toBe(false);
        }
        // Ungültige Adresse und andere Meldungen.
        expect(isAbortedFontDownload(message(2152398850, 'kaputt'), 'firefox', ORIGIN)).toBe(false);
        expect(
            isAbortedFontDownload(
                `Loading failed for the module with source "${FONT}". status=2152398850`,
                'firefox',
                ORIGIN,
            ),
        ).toBe(false);
    });
});
