import { onBeforeUnmount, ref, type Ref } from 'vue';

/** Takt für Zeitangaben wie „vor 5 Min.“ oder „heute fällig“. */
export const NOW_TICK_MS = 30_000;

/**
 * Aktuelle Zeit für eine Seite: alle NOW_TICK_MS neu und sofort, wenn die App wieder sichtbar wird
 * (nach dem Entsperren des Handys nicht erst beim nächsten Takt). Endet mit der Komponente.
 */
export function useNow(doc: Document = document): Ref<Date> {
    const now = ref(new Date());
    const tick = (): void => {
        now.value = new Date();
    };
    const timer = setInterval(tick, NOW_TICK_MS);
    doc.addEventListener('visibilitychange', tick);
    onBeforeUnmount(() => {
        clearInterval(timer);
        doc.removeEventListener('visibilitychange', tick);
    });
    return now;
}
