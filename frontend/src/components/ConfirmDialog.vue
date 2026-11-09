<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogOverlay,
    AlertDialogPortal,
    AlertDialogRoot,
    AlertDialogTitle,
} from 'reka-ui';

/**
 * Rückfrage vor einer Aktion mit Folgen (Reka UI AlertDialog, kein window.confirm). Der Fokus
 * steht beim Öffnen auf „Abbrechen“; Escape bricht ab. Gegen Doppeltipp (am iPhone wurde so eine
 * Rückfrage ungewollt bestätigt) nehmen die Knöpfe in den ersten `CONFIRM_GUARD_MS` nach dem
 * Öffnen keine Klicks an (`aria-disabled`, damit Tests warten); die Tastatur bleibt sofort bedienbar.
 */
const CONFIRM_GUARD_MS = 400;

defineProps<{
    title: string;
    description: string;
    confirmLabel: string;
    cancelLabel?: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();

const armed = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    open,
    (isOpen) => {
        clearTimeout(timer);
        armed.value = false;
        if (isOpen) {
            timer = setTimeout(() => {
                armed.value = true;
            }, CONFIRM_GUARD_MS);
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    clearTimeout(timer);
});

/** Klicks (Maus und Touch), nicht Tastatur: Enter/Leertaste lösen `detail` 0 aus. */
function guardClick(event: MouseEvent): void {
    if (!armed.value && event.detail > 0) {
        event.preventDefault();
        event.stopPropagation();
    }
}
</script>

<template>
    <AlertDialogRoot v-model:open="open">
        <AlertDialogPortal>
            <AlertDialogOverlay class="confirm-overlay" />
            <AlertDialogContent class="confirm-dialog" data-testid="confirm-dialog">
                <AlertDialogTitle class="confirm-title">{{ title }}</AlertDialogTitle>
                <AlertDialogDescription class="confirm-description">
                    {{ description }}
                </AlertDialogDescription>
                <div class="confirm-actions" @click.capture="guardClick">
                    <AlertDialogCancel class="btn" :aria-disabled="!armed">{{
                        cancelLabel ?? 'Abbrechen'
                    }}</AlertDialogCancel>
                    <AlertDialogAction
                        class="btn confirm-action"
                        :aria-disabled="!armed"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </AlertDialogAction>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>

<style scoped>
/* Über einem offenen FormDialog (Abdunklung 10, Fenster 30), z. B. „Eingaben verwerfen?“. */
.confirm-overlay {
    position: fixed;
    z-index: 40;
    inset: 0;
    background: var(--overlay);
}

.confirm-dialog {
    position: fixed;
    z-index: 45;
    top: 50%;
    left: 50%;
    width: min(440px, calc(100vw - 32px));
    padding: 20px;
    transform: translate(-50%, -50%);
    border-radius: var(--radius-lg);
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow-raised);
}

.confirm-title {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: var(--font-weight-bold);
}

.confirm-description {
    margin: 0 0 20px;
}

.confirm-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 12px;
}

.confirm-action {
    border-color: var(--accent);
    background: var(--accent);
    color: var(--on-accent);
}
</style>
