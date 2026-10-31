<script setup lang="ts">
import { computed, onMounted, ref, useId, watch } from 'vue';
import {
    BOOKABLE_STATUSES,
    STATUS_LABELS,
    type BookableStatus,
    type DeviceListItem,
} from '../api/devices';
import { adminLocationStore, bulkChangeState } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import {
    bulkCommentProblem,
    bulkCommentRequired,
    bulkStateProblem,
    type BulkRun,
} from '../data/deviceBulk';
import { commentPart } from '../data/deviceForm';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import FormDialog from './FormDialog.vue';

/**
 * Sammelaktion „Umbuchen / Status ändern“ (Admin, POST devices/bulk/state): neuer Standort und/oder
 * Status für alle ausgewählten Geräte, gemeinsamer Kommentar (bei „defekt“ Pflicht wie beim
 * Umbuchen). Nur aktive Standorte sind Ziele. Fortschritt wie bei „Prüfdatum setzen“.
 */
const props = defineProps<{
    devices: readonly DeviceListItem[];
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [run: BulkRun] }>();

const KEEP = 'keep';

const id = useId();
const location = ref(KEEP);
const status = ref<BookableStatus | typeof KEEP>(KEEP);
const comment = ref('');
const touched = ref(false);
const progress = ref<{ done: number; total: number } | null>(null);

watch(open, (value) => {
    if (value) {
        location.value = KEEP;
        status.value = KEEP;
        comment.value = '';
        touched.value = false;
        progress.value = null;
    }
});

onMounted(() => {
    void adminLocationStore.refresh('open');
});

const busy = computed(() => progress.value !== null);
const dialogOpen = computed({
    get: () => open.value,
    set: (value: boolean) => {
        if (!busy.value) {
            open.value = value;
        }
    },
});

const locationOptions = computed((): SelectOption[] => {
    const options: SelectOption[] = [{ value: KEEP, label: 'Standort nicht ändern' }];
    for (const entry of adminLocationStore.state.locations ?? []) {
        if (!entry.active) {
            continue;
        }
        options.push({ value: String(entry.id), label: entry.name });
        for (const child of entry.children) {
            if (child.active) {
                options.push({
                    value: String(child.id),
                    label: `${entry.name} / ${child.name}`,
                    level: 2,
                });
            }
        }
    }
    return options;
});

const form = computed(() => ({
    locationId: location.value === KEEP ? null : Number(location.value),
    status: status.value === KEEP ? null : status.value,
    comment: comment.value,
}));
const targetProblem = computed(() => (touched.value ? bulkStateProblem(form.value) : null));
const commentProblem = computed(() => (touched.value ? bulkCommentProblem(form.value) : null));
const commentRequired = computed(() => bulkCommentRequired(form.value));

/** Ungespeicherte Eingaben im Fenster (auch für FormDialog: Klick daneben, Escape). */
const unsaved = computed(
    () =>
        busy.value ||
        (open.value &&
            (location.value !== KEEP || status.value !== KEEP || comment.value.trim() !== '')),
);
useUnsavedInput(() => unsaved.value);

async function submit(): Promise<void> {
    touched.value = true;
    if (bulkStateProblem(form.value) !== null || bulkCommentProblem(form.value) !== null) {
        return;
    }
    if (busy.value) {
        return;
    }
    const { locationId, status: target } = form.value;
    progress.value = { done: 0, total: props.devices.length };
    const run = await bulkChangeState(
        props.devices,
        {
            ...(locationId !== null && { locationId }),
            ...(target !== null && { status: target }),
        },
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
        :unsaved="unsaved"
        :title="`Umbuchen / Status ändern (${devices.length === 1 ? '1 Gerät' : `${String(devices.length)} Geräte`})`"
        description="Gilt für alle ausgewählten Geräte; jedes erhält einen eigenen Eintrag im Verlauf."
        submit-label="Speichern"
        :busy="busy"
        :can-submit="!touched || (targetProblem === null && commentProblem === null)"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <div class="field">
            <AppSelect v-model="location" label="Neuer Standort" :options="locationOptions" />
        </div>
        <fieldset class="statuses">
            <legend class="field-label">Neuer Status</legend>
            <label class="check">
                <input v-model="status" type="radio" :name="`${id}-status`" :value="KEEP" />
                Status nicht ändern
            </label>
            <label v-for="entry in BOOKABLE_STATUSES" :key="entry" class="check">
                <input v-model="status" type="radio" :name="`${id}-status`" :value="entry" />
                {{ STATUS_LABELS[entry] }}
            </label>
        </fieldset>
        <p v-if="targetProblem !== null" class="field-error" data-testid="bulk-target-problem">
            {{ targetProblem }}
        </p>
        <label class="field">
            <span class="field-label">{{
                commentRequired ? 'Was ist defekt?' : 'Kommentar für den Verlauf (optional)'
            }}</span>
            <input
                v-model="comment"
                class="input"
                type="text"
                autocomplete="off"
                :disabled="busy"
                :maxlength="MAX_COMMENT_LENGTH"
                :aria-invalid="commentProblem !== null"
                :aria-describedby="commentProblem !== null ? `${id}-comment` : undefined"
            />
            <p v-if="commentProblem !== null" :id="`${id}-comment`" class="field-error">
                {{ commentProblem }}
            </p>
        </label>
        <p v-if="progress !== null" class="progress" role="status" data-testid="bulk-progress">
            {{ progress.done }} von {{ progress.total }} gesendet …
        </p>
    </FormDialog>
</template>

<style scoped>
.statuses {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 20px;
    margin: 0;
    padding: 0;
    border: 0;
}

.statuses legend {
    margin-bottom: 4px;
    padding: 0;
}

.progress {
    margin: 0;
    font-weight: var(--font-weight-semibold);
}
</style>
