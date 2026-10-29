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
