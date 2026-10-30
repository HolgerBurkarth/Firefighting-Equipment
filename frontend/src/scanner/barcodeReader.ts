import { prepareZXingModule, readBarcodes, type ReaderOptions } from 'zxing-wasm/reader';
// Die WASM-Datei liegt im Build unter assets/ (Hash im Namen); ohne diese Angabe holte die
// Bibliothek sie von jsDelivr (die CSP erlaubt nur 'self').
import wasmUrl from 'zxing-wasm/reader/zxing_reader.wasm?url';

/**
 * Lesemodul des Kamera-Scanners (KONZEPT.md, Entscheidung 27): zxing-wasm, nur das Lesemodul. Diese
 * Datei lädt erst beim Öffnen des Scanners (dynamischer Import in `CodeScanner.vue`), die WASM-Datei
 * (rund 900 KiB, komprimiert ausgeliefert) beim ersten Bild. Immer diese Bibliothek, auch wo der
 * Browser einen eigenen `BarcodeDetector` hat: gleiche Ergebnisse auf allen Handys.
 */

/** Liest einen Code aus einem Bild; null = kein Code erkannt. */
export type BarcodeReader = (image: ImageData) => Promise<string | null>;

/**
 * `Plain`: der Inhalt unverändert, bei GS1 mit den Trennzeichen GS (0x1D) wie der Scan-Code im
 * Backend (`ScanCode`) und ein USB-Scanner; der Standard `HRI` schriebe „(01)…(10)…“.
 */
export const READER_OPTIONS: ReaderOptions = {
    formats: [],
    textMode: 'Plain',
    maxNumberOfSymbols: 1,
};

let prepared: Promise<unknown> | null = null;

/** Lädt und übersetzt das WASM-Modul (einmal je Seitenaufruf; nach einem Fehler erneut). */
export async function loadBarcodeReader(): Promise<BarcodeReader> {
    prepared ??= prepareZXingModule({
        overrides: {
            locateFile: (path: string, prefix: string) =>
                path.endsWith('.wasm') ? wasmUrl : prefix + path,
        },
        fireImmediately: true,
    });
    try {
        await prepared;
    } catch (error) {
        prepared = null;
        throw error;
    }
    return async (image) => {
        const results = await readBarcodes(image, READER_OPTIONS);
        return results.find((result) => result.isValid && result.text !== '')?.text ?? null;
    };
}
