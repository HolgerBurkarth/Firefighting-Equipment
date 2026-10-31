<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import { conflictDeviceDetail, type DeviceDetail } from '../api/devices';
import { saveInspectionDue } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import { germanDate } from '../data/changeTime';
import { commentPart, DATE_MAX, DATE_MIN, dateProblem, parseDateInput } from '../data/deviceForm';
import { useUnsavedInput } from '../runtime/unsavedInput';
import FormDialog from './FormDialog.vue';

/**
 * Nächste Prüfung setzen oder entfernen (Admin, POST devices/{id}/inspection-due). Leeres Feld =
 * kein Prüfdatum.
 */
const props = defineProps<{
    device: DeviceDetail;
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{
    saved: [device: DeviceDetail, message: string];
    conflict: [device: DeviceDetail];
}>();

const id = useId();
const due = ref('');
const comment = ref('');
const touched = ref(false);
const busy = ref(false);
const error = ref<ApiError | null>(null);
const conflict = ref(false);

watch(open, (value) => {
    if (value) {
        due.value = props.device.nextInspectionDue ?? '';
        comment.value = '';
        touched.value = false;
        error.value = null;
        conflict.value = false;
    }
});

const problem = computed(() => dateProblem(due.value));
const shownProblem = computed(() => (touched.value ? problem.value : null));
const changed = computed(
    () => (parseDateInput(due.value) ?? null) !== props.device.nextInspectionDue,
);

/** Ungespeicherte Eingaben im Fenster (auch für FormDialog: Klick daneben, Escape). */
const unsaved = computed(() => open.value && (changed.value || comment.value.trim() !== ''));
useUnsavedInput(() => unsaved.value);

const currentText = computed(() =>
    props.device.nextInspectionDue === null
        ? 'Bisher ist kein Prüfdatum festgelegt.'
        : `Bisher: ${germanDate(props.device.nextInspectionDue)}`,
);

async function submit(): Promise<void> {
    touched.value = true;
    error.value = null;
    const date = parseDateInput(due.value);
    if (problem.value !== null || date === undefined || busy.value) {
        return;
    }
    if (!changed.value) {
        open.value = false;
        return;
    }
    busy.value = true;
    const result = await saveInspectionDue(props.device.id, {
        version: props.device.version,
        nextInspectionDue: date,
        ...commentPart(comment.value),
    });
    busy.value = false;
    if (result.ok) {
        open.value = false;
        const identifier = result.data.identifier;
        emit(
            'saved',
            result.data,
            date === null
                ? `Prüfdatum von ${identifier} entfernt.`
                : `Nächste Prüfung von ${identifier}: ${germanDate(date)}.`,
        );
        return;
    }
    const latest =
        result.error.code === 'version_conflict'
            ? conflictDeviceDetail(result.error.details)
            : null;
    if (latest !== null) {
        conflict.value = true;
        emit('conflict', latest);
    } else {
        error.value = result.error;
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :unsaved="unsaved"
        :title="`Prüfdatum von ${device.identifier}`"
        :description="currentText"
        submit-label="Speichern"
        :busy="busy"
        :can-submit="!touched || problem === null"
        :error="error"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <p v-if="conflict" class="notice notice-info" role="alert" data-testid="due-conflict">
            Das Gerät wurde inzwischen geändert (siehe oben). Bitte prüfen und erneut speichern.
        </p>
        <label class="field">
            <span class="field-label">Nächste Prüfung fällig am</span>
            <input
                v-model="due"
                class="input"
                type="date"
                :min="DATE_MIN"
                :max="DATE_MAX"
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
                :maxlength="MAX_COMMENT_LENGTH"
            />
        </label>
    </FormDialog>
</template>

<style scoped>
.field-hint {
    font-size: var(--font-size-sm);
    color: var(--muted);
}
</style>
