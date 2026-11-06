import type { ObjectDirective } from 'vue';

/**
 * Elemente, die selbst etwas tun; ein Klick darauf (oder in eine Fläche mit `data-row-ignore`, z. B.
 * die Spalte eines Kästchens, damit ein knapp verfehltes Kästchen nichts öffnet) öffnet die Zeile
 * nicht.
 */
export const ROW_LINK_IGNORE =
    'a, button, input, label, select, textarea, [role="button"], [data-row-ignore]';

/** Link einer Zeile, den ein Klick irgendwo in der Zeile auslöst. */
export const ROW_LINK_ATTRIBUTE = 'data-row-link';

/**
 * Öffnet ein Klick in `row` deren Link? Nur die linke Maustaste ohne Zusatztaste, nicht auf
 * Elementen aus `ROW_LINK_IGNORE` innerhalb der Zeile und nicht, wenn gerade Text markiert wurde.
 */
export function opensRow(row: Element, event: MouseEvent): boolean {
    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return false;
    }
    const target = event.target instanceof Element ? event.target : null;
    const own = target?.closest(ROW_LINK_IGNORE) ?? null;
    if (own !== null && row.contains(own)) {
        return false;
    }
    return window.getSelection()?.isCollapsed !== false;
}

const handlers = new WeakMap<HTMLElement, (event: MouseEvent) => void>();

/**
 * `v-row-link` an einer Zeile bzw. Karte: Ein Klick irgendwo darin tut dasselbe wie ein Klick auf
 * ihren Link mit `data-row-link` (Regel für alle Listen, deren Name ein Link ist; zuerst T9 in der
 * Geräteliste). Per Tastatur bleibt der Link selbst. Ohne Link (z. B. ohne Recht auf das Ziel) tut
 * der Klick nichts; Mauszeiger und Hinterlegung setzt `base.css` nur mit Link (`data-row-open`).
 */
export const vRowLink: ObjectDirective<HTMLElement> = {
    mounted(el) {
        const handler = (event: MouseEvent): void => {
            if (!opensRow(el, event)) {
                return;
            }
            el.querySelector<HTMLElement>(`[${ROW_LINK_ATTRIBUTE}]`)?.click();
        };
        handlers.set(el, handler);
        el.addEventListener('click', handler);
        el.setAttribute('data-row-open', '');
    },
    beforeUnmount(el) {
        const handler = handlers.get(el);
        if (handler !== undefined) {
            el.removeEventListener('click', handler);
            handlers.delete(el);
        }
    },
};
