<script setup lang="ts">
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
 * steht beim Öffnen auf „Abbrechen“; Escape bricht ab.
 */
defineProps<{
    title: string;
    description: string;
    confirmLabel: string;
    cancelLabel?: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();
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
                <div class="confirm-actions">
                    <AlertDialogCancel class="btn">{{
                        cancelLabel ?? 'Abbrechen'
                    }}</AlertDialogCancel>
                    <AlertDialogAction class="btn confirm-action" @click="emit('confirm')">
                        {{ confirmLabel }}
                    </AlertDialogAction>
                </div>
            </AlertDialogContent>
        </AlertDialogPortal>
    </AlertDialogRoot>
</template>

<style scoped>
.confirm-overlay {
    position: fixed;
    inset: 0;
    background: var(--overlay);
}

.confirm-dialog {
    position: fixed;
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
