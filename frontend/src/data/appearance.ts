import { DEFAULT_ACCENT } from '../runtime/accent';

/**
 * Erscheinungsbild der Stadt in den Einstellungen (KONZEPT.md, "Bereiche des Admins",
 * Einstellungen; Entscheidung 37): Prüfung der Akzentfarbe beim Tippen wie AccentColor in PHP (nie
 * strenger, verbindlich bleibt der Server) und die Maße des Logos, das die App selbst auf
 * 512 × 512 PNG bringt.
 */

/** WCAG 2.x, Stufe AA (AccentColor::MIN_CONTRAST). */
export const MIN_CONTRAST = 4.5;

/** Kantenlänge des Logos (PngInspector::MIN_EDGE und MAX_EDGE). */
export const LOGO_EDGE = 512;

/** Größte PNG-Datei, die der Server annimmt (PngInspector::MAX_BYTES, 500 KB). */
export const MAX_LOGO_BYTES = 512_000;

/** Größte gewählte Bilddatei (die App liest sie nur, um sie neu zu zeichnen). */
export const MAX_LOGO_SOURCE_BYTES = 10 * 1_048_576;

/** Rand um das Bild im Quadrat (je Seite), damit runde App-Symbole nichts abschneiden. */
export const LOGO_MARGIN = 16;

/** Dateien, die der Browser als Bild liest (für `accept` der Dateiauswahl). */
export const LOGO_ACCEPT = 'image/png,image/jpeg,image/svg+xml,image/webp,image/gif';

/** Feste Farben zur Auswahl; `null` = Standardfarbe (Feuerwehrrot, AccentColor::DEFAULT). */
export const ACCENT_SWATCHES: readonly { readonly color: string | null; readonly label: string }[] =
    [
        { color: null, label: 'Feuerwehrrot (Standard)' },
        { color: '#1d3557', label: 'Dunkelblau' },
        { color: '#1565c0', label: 'Blau' },
        { color: '#2e5e3e', label: 'Grün' },
        { color: '#4b3b7a', label: 'Violett' },
        { color: '#2b3440', label: 'Anthrazit' },
    ];

const FORMAT_MESSAGE = 'Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).';

/**
 * Eingabe wie AccentColor::fromString (ohne Kontrast): Leerraum am Rand weg (wie PHP `trim()`),
 * `#` und 6 oder 3 Hex-Ziffern, Ergebnis `#rrggbb` klein; sonst null.
 */
export function parseAccent(input: string): string | null {
    const value = input.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');
    if (!/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(value)) {
        return null;
    }
    const lower = value.toLowerCase();
    if (lower.length === 4) {
        return `#${lower[1]}${lower[1]}${lower[2]}${lower[2]}${lower[3]}${lower[3]}`;
    }
    return lower;
}

/** Kontrast weißer Schrift auf `#rrggbb` nach WCAG 2.x (wie AccentColor::contrastOf). */
export function contrastWithWhite(hex: string): number {
    const weights: readonly (readonly [number, number])[] = [
        [1, 0.2126],
        [3, 0.7152],
        [5, 0.0722],
    ];
    let luminance = 0;
    for (const [offset, weight] of weights) {
        const channel = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        const linear = channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        luminance += weight * linear;
    }
    return 1.05 / (luminance + 0.05);
}

/** Abgerundet auf zwei Stellen (nie „4,50“ bei einer abgelehnten Farbe), deutsch geschrieben. */
export function contrastText(contrast: number): string {
    return (Math.floor(contrast * 100) / 100).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export type AccentCheck =
    | { readonly ok: true; readonly color: string; readonly contrast: number }
    | { readonly ok: false; readonly color: string | null; readonly message: string };

/** Prüfung beim Tippen: Format, dann Kontrast (Meldungen wie der Server). */
export function checkAccent(input: string): AccentCheck {
    const color = parseAccent(input);
    if (color === null) {
        return { ok: false, color: null, message: FORMAT_MESSAGE };
    }
    const contrast = contrastWithWhite(color);
    if (contrast < MIN_CONTRAST) {
        return {
            ok: false,
            color,
            message:
                `Zu hell: Weiße Schrift auf ${color.toUpperCase()} erreicht nur einen Kontrast von ` +
                `${contrastText(contrast)}:1; nötig sind mindestens 4,5:1. Bitte eine dunklere Farbe wählen.`,
        };
    }
    return { ok: true, color, contrast };
}

/** Gespeicherte Auswahl: die Standardfarbe gilt als „Standard“ (`null`), auch wenn sie gesetzt ist. */
export function accentSelection(color: string): string | null {
    return color === DEFAULT_ACCENT ? null : color;
}

/** Wert für POST tenant/appearance: `null` = Standardfarbe. */
export function accentRequest(selection: string | null): string | null {
    return selection === null || selection === DEFAULT_ACCENT ? null : selection;
}

/** Wo das Bild im Quadrat liegt: so groß wie möglich innerhalb des Rands, mittig. */
export interface LogoPlacement {
    readonly x: number;
    readonly y: number;
    readonly width: number;
    readonly height: number;
}

/**
 * Bild mit `width` × `height` in das Quadrat LOGO_EDGE (Rand LOGO_MARGIN je Seite): Seitenverhältnis
 * bleibt, nicht quadratische Bilder erhalten oben/unten bzw. links/rechts transparenten Rand.
 * Ohne Maße (manche SVG) gilt das Bild als quadratisch.
 */
export function placeLogo(width: number, height: number): LogoPlacement {
    const box = LOGO_EDGE - 2 * LOGO_MARGIN;
    const known = width > 0 && height > 0 && Number.isFinite(width) && Number.isFinite(height);
    const w = known ? width : 1;
    const h = known ? height : 1;
    const scale = Math.min(box / w, box / h);
    const drawnWidth = Math.round(w * scale);
    const drawnHeight = Math.round(h * scale);
    return {
        x: Math.round((LOGO_EDGE - drawnWidth) / 2),
        y: Math.round((LOGO_EDGE - drawnHeight) / 2),
        width: drawnWidth,
        height: drawnHeight,
    };
}

/** KB wie die Meldung von PngInspector (1 KB = 1024 Byte; 512 000 Byte = 500 KB). */
function kilobytes(bytes: number): string {
    return Math.round(bytes / 1024).toLocaleString('de-DE');
}

/** Meldung zur gewählten Datei vor dem Lesen (leer, zu groß), sonst null. */
export function logoSourceProblem(size: number): string | null {
    if (size === 0) {
        return 'Die Datei ist leer.';
    }
    if (size > MAX_LOGO_SOURCE_BYTES) {
        return 'Die Datei ist zu groß (höchstens 10 MB). Bitte ein kleineres Bild wählen.';
    }
    return null;
}

/** Meldung, wenn das erzeugte PNG die Grenze des Servers überschreitet, sonst null. */
export function logoSizeProblem(size: number): string | null {
    if (size <= MAX_LOGO_BYTES) {
        return null;
    }
    return (
        `Das Logo wird als PNG zu groß (${kilobytes(size)} KB, höchstens 500 KB). ` +
        'Bitte ein einfacheres Bild wählen, z. B. ohne Foto im Hintergrund.'
    );
}

export const LOGO_UNREADABLE =
    'Die Datei konnte nicht als Bild gelesen werden. Bitte ein PNG, JPG oder SVG wählen.';
