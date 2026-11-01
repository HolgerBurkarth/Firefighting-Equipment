/** Ober- und Unterkante wie `getBoundingClientRect()` (px, relativ zum Fenster). */
export interface VerticalBox {
    readonly top: number;
    readonly bottom: number;
}

/**
 * Wie weit nach unten zu scrollen ist, damit ein aufgeklappter Bereich sichtbar wird (T3): so weit,
 * dass sein Ende im Bild ist, höchstens so weit, dass sein Kopf direkt unter der festen Kopfzeile
 * steht (`view.top`). Nie nach oben; 0, wenn das Ende schon sichtbar ist.
 */
export function revealOffset(box: VerticalBox, view: VerticalBox): number {
    if (box.bottom <= view.bottom) {
        return 0;
    }
    return Math.max(0, Math.min(box.bottom - view.bottom, box.top - view.top));
}
