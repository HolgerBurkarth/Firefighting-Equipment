import type { RouteLocationRaw, Router } from 'vue-router';

/**
 * Zurück-Knopf der Kopfzeile: wie der Knopf des Browsers, wenn die vorige Seite zur App gehört
 * (Vue Router merkt sie sich in `history.state.back`); sonst, z. B. nach einem direkt geöffneten
 * Link, zu `fallback`.
 */
export async function goBack(router: Router, fallback: RouteLocationRaw): Promise<void> {
    if (typeof router.options.history.state['back'] === 'string') {
        router.back();
        return;
    }
    await router.push(fallback);
}

/**
 * Nach dem Speichern zu einer festen Seite (z. B. „Suchen“, KONZEPT.md, "User am Handy –
 * Entscheidungen", 22): War sie die vorige Seite, zurück wie der Knopf des Browsers (sonst stünde
 * sie zweimal in der Historie und Zurück führte wieder zum Formular); sonst ersetzt sie die
 * aktuelle Seite.
 */
export async function returnTo(router: Router, name: string): Promise<void> {
    const back: unknown = router.options.history.state['back'];
    if (typeof back === 'string' && router.resolve(back).name === name) {
        router.back();
        return;
    }
    await router.replace({ name });
}
