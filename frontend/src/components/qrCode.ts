import { encode } from 'uqr';

/** Ruhezone um den Code in Modulen (ISO/IEC 18004: mindestens 4). */
export const QR_QUIET_ZONE = 4;

/** Raster eines QR-Codes als SVG-Pfad (ein Rechteck je Lauf dunkler Module einer Zeile). */
export interface QrShape {
    /** Kantenlänge in Modulen ohne Ruhezone. */
    readonly size: number;
    readonly path: string;
}

/**
 * QR-Code eines Textes (Bibliothek uqr: nur das Raster, gezeichnet wird als SVG in QrCode.vue; kein
 * Canvas, keine Bild-Adresse, die CSP bleibt unverändert). Fehlerkorrektur M (15 %), genug für einen
 * Aushang mit Knicken und Flecken bei kurzen Links.
 */
export function qrShape(text: string): QrShape {
    const { size, data } = encode(text, { ecc: 'M', border: 0 });
    return { size, path: qrPath(data) };
}

export function qrPath(rows: readonly (readonly boolean[])[]): string {
    const parts: string[] = [];
    rows.forEach((row, y) => {
        let x = 0;
        while (x < row.length) {
            if (row[x] !== true) {
                x += 1;
                continue;
            }
            const start = x;
            while (row[x] === true) {
                x += 1;
            }
            parts.push(
                `M${String(start)} ${String(y)}h${String(x - start)}v1h-${String(x - start)}z`,
            );
        }
    });
    return parts.join('');
}
