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
