import { computed, onBeforeUnmount, shallowReactive } from 'vue';

/**
 * Zentrale Stelle für „ungespeicherte Eingaben“. Jedes Formular meldet hier, ob etwas eingegeben,
 * aber noch nicht gespeichert ist (useUnsavedInput). Die App aktualisiert sich dann nicht von
 * selbst (appUpdate.ts), sondern zeigt einen Hinweis; Eingaben gehen so nie verloren.
 */
const sources = shallowReactive(new Set<() => boolean>());

/** true, solange mindestens ein Formular ungespeicherte Eingaben meldet (reaktiv). */
export const hasUnsavedInput = computed(() => [...sources].some((isDirty) => isDirty()));

/**
 * Meldet eine Quelle an; `isDirty` liest reaktive Werte (z. B. Refs der Felder). Rückgabe: zum
 * Abmelden. In Komponenten stattdessen useUnsavedInput verwenden.
 */
export function registerUnsavedInput(isDirty: () => boolean): () => void {
    const source = (): boolean => isDirty();
    sources.add(source);
    return () => {
        sources.delete(source);
    };
}

/**
 * Für Formulare (im setup): meldet ungespeicherte Eingaben, solange die Komponente besteht.
 * `isDirty` soll nach dem Speichern false liefern (z. B. Felder geleert oder Erfolg angezeigt).
 */
export function useUnsavedInput(isDirty: () => boolean): void {
    onBeforeUnmount(registerUnsavedInput(isDirty));
}
