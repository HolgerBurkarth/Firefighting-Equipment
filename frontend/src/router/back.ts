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

/**
 * Schließt eine Ansicht, die nur ein Parameter der Adresse öffnet (z. B. die Standortwahl
 * `?standortwahl=1` auf der Umbuchen-Seite): War die Seite ohne ihn die vorige, zurück wie der
 * Knopf des Browsers (so schließt auch dieser die Ansicht); sonst, z. B. nach dem Neuladen, ersetzt
 * die Adresse ohne Parameter die aktuelle.
 */
export async function closeQueryView(router: Router, key: string): Promise<void> {
    const current = router.currentRoute.value;
    const query = Object.fromEntries(
        Object.entries(current.query).filter(([name]) => name !== key),
    );
    const target = router.resolve({ path: current.path, query, hash: current.hash });
    const back: unknown = router.options.history.state['back'];
    if (typeof back === 'string' && router.resolve(back).fullPath === target.fullPath) {
        router.back();
        return;
    }
    await router.replace(target);
}
