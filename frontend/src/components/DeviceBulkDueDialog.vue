<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { DeviceListItem } from '../api/devices';
import { bulkSetInspectionDue } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import type { BulkRun } from '../data/deviceBulk';
import { commentPart, DATE_MAX, DATE_MIN, dateProblem, parseDateInput } from '../data/deviceForm';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * Sammelaktion „Prüfdatum setzen“ (Admin, POST devices/bulk/inspection-due): ein Datum für alle
 * ausgewählten Geräte (leer = entfernen), gemeinsamer Kommentar. Größere Auswahlen gehen in Blöcken;
 * solange zeigt das Fenster den Fortschritt („8 von 12“).
 */
const props = defineProps<{
    devices: readonly DeviceListItem[];
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [run: BulkRun] }>();

const id = useId();
const due = ref('');
const comment = ref('');
const touched = ref(false);
const progress = ref<{ done: number; total: number } | null>(null);

watch(open, (value) => {
    if (value) {
        due.value = '';
        comment.value = '';
        touched.value = false;
        progress.value = null;
    }
});

const busy = computed(() => progress.value !== null);
/** Während des Sendens bleibt das Fenster offen (Escape und „Abbrechen“ wirken erst danach). */
const dialogOpen = computed({
    get: () => open.value,
    set: (value: boolean) => {
        if (!busy.value) {
            open.value = value;
        }
    },
});
const problem = computed(() => dateProblem(due.value));
const shownProblem = computed(() => (touched.value ? problem.value : null));

useUnsavedInput(
    () => busy.value || (open.value && (due.value !== '' || comment.value.trim() !== '')),
);

async function submit(): Promise<void> {
    touched.value = true;
    const date = parseDateInput(due.value);
    if (problem.value !== null || date === undefined || busy.value) {
        return;
    }
    progress.value = { done: 0, total: props.devices.length };
    const run = await bulkSetInspectionDue(
        props.devices,
        date,
        commentPart(comment.value).comment,
        (done, total) => {
            progress.value = { done, total };
        },
    );
    progress.value = null;
    open.value = false;
    emit('done', run);
}
</script>

<template>
    <FormDialog
        v-model:open="dialogOpen"
        :title="`Prüfdatum für ${devices.length === 1 ? '1 Gerät' : `${String(devices.length)} Geräte`}`"
        description="Gilt für alle ausgewählten Geräte; jedes erhält einen eigenen Eintrag im Verlauf."
        submit-label="Prüfdatum setzen"
        :busy="busy"
        :can-submit="!touched || problem === null"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <label class="field">
            <span class="field-label">Nächste Prüfung fällig am</span>
            <input
                v-model="due"
                class="input"
                type="date"
                :min="DATE_MIN"
                :max="DATE_MAX"
                :disabled="busy"
                :aria-invalid="shownProblem !== null"
                :aria-describedby="`${id}-hint${shownProblem !== null ? ` ${id}-error` : ''}`"
                @input="touched = true"
            />
            <span :id="`${id}-hint`" class="field-hint">
                Leer lassen, um das Prüfdatum zu entfernen.
            </span>
            <p v-if="shownProblem !== null" :id="`${id}-error`" class="field-error">
                {{ shownProblem }}
            </p>
        </label>
        <label class="field">
            <span class="field-label">Kommentar für den Verlauf (optional)</span>
            <input
                v-model="comment"
                class="input"
                type="text"
                autocomplete="off"
                :disabled="busy"
                :maxlength="MAX_COMMENT_LENGTH"
            />
        </label>
        <p v-if="progress !== null" class="progress" role="status" data-testid="bulk-progress">
            {{ progress.done }} von {{ progress.total }} gesendet …
        </p>
    </FormDialog>
</template>

<style scoped>
.field-hint {
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.progress {
    margin: 0;
    font-weight: var(--font-weight-semibold);
}
</style>
