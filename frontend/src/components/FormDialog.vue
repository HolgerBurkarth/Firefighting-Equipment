<script setup lang="ts">
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { ref } from 'vue';
import type { ApiError } from '../api/client';
import ConfirmDialog from './ConfirmDialog.vue';
import ErrorNotice from './ErrorNotice.vue';

/**
 * Kleines Fenster mit Formular (Reka UI Dialog: Fokus bleibt darin, Escape und „Abbrechen“
 * schließen, danach zurück auf den auslösenden Knopf). Die Felder kommen in den Standard-Slot,
 * seltene Aktionen (z. B. „Sperren“) abgesetzt in den Slot `extra`.
 *
 * Eingaben gehen nie unbemerkt verloren (Live-Test 31.10.2026): Mit ungespeicherten Eingaben
 * (`unsaved`, derselbe Wert wie für useUnsavedInput) schließt ein Klick daneben das Fenster nicht,
 * Escape fragt nach („Eingaben verwerfen?“, Standard „Weiter bearbeiten“); „Abbrechen“ schließt
 * ohne Rückfrage. Ein Klick auf eine Fläche mit `data-dialog-passthrough` (Update-Hinweis) zählt nie
 * als Klick daneben; base.css legt sie über die Abdunklung.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string | null;
        submitLabel: string;
        /** Anfrage läuft: Knöpfe gesperrt. */
        busy?: boolean;
        /** Absenden erst, wenn die Prüfung beim Tippen nichts findet. */
        canSubmit?: boolean;
        /** Fehler der letzten Anfrage, der zu keinem Feld gehört. */
        error?: ApiError | null;
        /**
         * Knopf, der das Fenster geöffnet hat; bekommt danach den Fokus zurück. Nötig, weil das
         * Fenster nicht über einen DialogTrigger aufgeht und Safari/WebKit einen Knopf beim Klick
         * nicht fokussiert (Reka UI gäbe den Fokus sonst an den Seitenanfang).
         */
        returnFocus?: HTMLElement | null;
        /** `danger`: Aktion mit Folgen (z. B. „Aussondern“), Knopf rot. */
        variant?: 'accent' | 'danger';
        /** Ungespeicherte Eingaben im Fenster (wie useUnsavedInput der Seite). */
        unsaved?: boolean;
    }>(),
    // Boolesche Props wären ohne Angabe false; Absenden ist ohne Angabe erlaubt.
    {
        description: null,
        busy: false,
        canSubmit: true,
        error: null,
        returnFocus: null,
        variant: 'accent',
        unsaved: false,
    },
);
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ submit: [] }>();
/** Rückfrage nach Escape mit ungespeicherten Eingaben. */
const discardOpen = ref(false);

/** Klick oder Fokus neben dem Fenster (Reka UI): schließt nur ohne Eingaben und ohne Anfrage. */
function onInteractOutside(event: CustomEvent<{ originalEvent: Event }>): void {
    const target = event.detail.originalEvent.target;
    const passthrough =
        target instanceof Element && target.closest('[data-dialog-passthrough]') !== null;
    if (props.busy || props.unsaved || passthrough) {
        event.preventDefault();
    }
}

function onEscapeKeyDown(event: KeyboardEvent): void {
    if (props.busy) {
        event.preventDefault();
    } else if (props.unsaved) {
        event.preventDefault();
        discardOpen.value = true;
    }
}

function onCloseAutoFocus(event: Event): void {
    // Steht der Knopf nicht mehr auf der Seite (z. B. nach dem Sperren ausgeblendet), bleibt es bei
    // Reka UI.
    if (props.returnFocus?.isConnected === true) {
        event.preventDefault();
        props.returnFocus.focus();
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay class="form-overlay" />
            <DialogContent
                class="form-dialog"
                data-testid="form-dialog"
                data-form-dialog
                @close-auto-focus="onCloseAutoFocus"
                @interact-outside="onInteractOutside"
                @escape-key-down="onEscapeKeyDown"
            >
                <DialogTitle class="form-title">{{ title }}</DialogTitle>
                <DialogDescription v-if="description !== null" class="form-description">
                    {{ description }}
                </DialogDescription>
                <DialogDescription v-else class="visually-hidden">{{ title }}</DialogDescription>
                <form class="form-body" novalidate @submit.prevent="emit('submit')">
                    <slot />
                    <ErrorNotice v-if="error !== null" :error="error" />
                    <div class="form-actions">
                        <DialogClose class="btn" type="button">Abbrechen</DialogClose>
                        <button
                            class="btn"
                            :class="`btn-${variant}`"
                            type="submit"
                            :disabled="busy || !canSubmit"
                        >
                            {{ submitLabel }}
                        </button>
                    </div>
                </form>
                <div v-if="$slots['extra']" class="form-extra">
                    <slot name="extra" />
                </div>
                <ConfirmDialog
                    v-model:open="discardOpen"
                    title="Eingaben verwerfen?"
                    description="Ihre Eingaben in diesem Fenster gehen verloren."
                    confirm-label="Verwerfen"
                    cancel-label="Weiter bearbeiten"
                    @confirm="open = false"
                />
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>

<style scoped>
/* Reihenfolge: Abdunklung, Update-Hinweis (base.css, rückt das Fenster nach unten), Fenster, Rückfragen. */
.form-overlay {
    position: fixed;
    z-index: 10;
    inset: 0;
    background: var(--overlay);
}

.form-dialog {
    position: fixed;
    z-index: 30;
    top: 50%;
    left: 50%;
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: min(480px, calc(100vw - 32px));
    max-height: calc(100dvh - 32px);
    padding: 20px;
    overflow-y: auto;
    transform: translate(-50%, -50%);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-raised);
}

.form-title {
    margin: 0;
    font-size: 19px;
    font-weight: var(--font-weight-bold);
}

.form-description {
    margin: -8px 0 0;
    color: var(--muted);
}

.form-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.form-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 12px;
}

/* Seltene Aktionen mit Folgen abseits, unter einer Linie (KONZEPT.md, "Listen"). */
.form-extra {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
}
</style>
