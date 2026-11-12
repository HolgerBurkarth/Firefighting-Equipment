import type { Page } from '@playwright/test';

/**
 * Wie weit der Inhalt waagerecht über die Seite hinausragt (0 = passt). Verglichen wird mit der
 * Breite von <body>, nicht mit `clientWidth`: Playwright-WebKit unter Windows zieht im Handy-Profil
 * eine 20 px breite Bildlaufleiste von `clientWidth` ab, die Seite bleibt aber 390 px breit (auch
 * die unveränderte Anmeldeseite meldete dort 391 gegen 371; gemessen 30.10.2026).
 */
export function horizontalOverflow(page: Page): Promise<number> {
    return page.evaluate(
        () =>
            document.documentElement.scrollWidth -
            Math.ceil(document.body.getBoundingClientRect().width),
    );
}

/** Ein Bedienelement, das in einer Zeile nicht erreichbar ist (Runde 14, Punkt 2). */
export interface UnreachableControl {
    readonly row: number;
    readonly control: string;
    readonly reason: string;
}

const CONTROLS = 'button, a[href], input, [role="button"], [role="checkbox"], [role="switch"]';

/**
 * Prüft für jede Zeile bzw. Karte (`rows`), dass jedes sichtbare Bedienelement darin ganz im Fenster
 * liegt und nicht von einem Elternelement abgeschnitten wird (Mitte des Elements liegt wirklich auf dem
 * Element, `elementFromPoint`). Ein Knopf, den nur waagerechtes Scrollen oder ein Überstand erreicht,
 * zählt als nicht erreichbar. Leere Liste = Fehler (die Zeilen müssen vorhanden sein).
 */
export async function unreachableControls(page: Page, rows: string): Promise<UnreachableControl[]> {
    return page.evaluate(
        ({ rowSelector, controlSelector }) => {
            const problems: { row: number; control: string; reason: string }[] = [];
            const rowElements = Array.from(document.querySelectorAll(rowSelector));
            if (rowElements.length === 0) {
                return [{ row: -1, control: rowSelector, reason: 'keine Zeilen gefunden' }];
            }
            const width = document.body.getBoundingClientRect().width;
            rowElements.forEach((row, index) => {
                for (const control of Array.from(row.querySelectorAll(controlSelector))) {
                    const style = getComputedStyle(control);
                    const box = control.getBoundingClientRect();
                    if (
                        style.display === 'none' ||
                        style.visibility === 'hidden' ||
                        (box.width === 0 && box.height === 0) ||
                        (control instanceof HTMLInputElement && control.type === 'hidden') ||
                        control.closest('[hidden], [data-row-ignore-layout]') !== null ||
                        // Bildschirmleser-Texte (sr-only) und versteckte Dateiauswahl
                        (box.width <= 1 && box.height <= 1)
                    ) {
                        continue;
                    }
                    const name =
                        control.getAttribute('aria-label') ??
                        control.textContent.trim().slice(0, 40);
                    const label = name === '' ? control.tagName.toLowerCase() : name;
                    if (box.left < -0.5 || box.right > width + 0.5) {
                        problems.push({
                            row: index,
                            control: label,
                            reason: 'außerhalb des Fensters',
                        });
                        continue;
                    }
                    const x = box.left + box.width / 2;
                    const y = box.top + box.height / 2;
                    if (y < 0 || y > window.innerHeight) {
                        continue; // unterhalb des sichtbaren Teils: erreichbar durch Scrollen der Seite
                    }
                    const hit = document.elementFromPoint(x, y);
                    if (
                        hit === null ||
                        (hit !== control && !control.contains(hit) && !hit.contains(control))
                    ) {
                        problems.push({
                            row: index,
                            control: label,
                            reason: 'abgeschnitten oder verdeckt',
                        });
                    }
                }
            });
            return problems;
        },
        { rowSelector: rows, controlSelector: CONTROLS },
    );
}
