<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { ApiError } from '../api/client';
import type { LoanPlace } from '../api/loans';
import { deviceStore, lendEquipment } from '../data';
import { MAX_COMMENT_LENGTH } from '../data/booking';
import type { BulkRun } from '../data/deviceBulk';
import { DATE_MAX, DATE_MIN, dateProblem, parseDateInput } from '../data/deviceForm';
import { lendProblem, type LendCandidate } from '../data/loans';
import { useUnsavedInput } from '../runtime/unsavedInput';
import AppSelect, { type SelectOption } from './AppSelect.vue';
import ErrorNotice from './ErrorNotice.vue';
import FormDialog from './FormDialog.vue';

/**
 * „Verleihen“ (Phase 2, P2.5; POST loans): Ausrüstung per Nummer oder Scan-Code (Handscanner tippt, Enter
 * fügt hinzu) sammeln, Leihnehmer („Ausgeliehen an“), Ansprechpartner, „Rückgabe bis“ und Kommentar. Größere
 * Mengen gehen in Blöcken; solange zeigt das Fenster den Fortschritt.
 */
const props = defineProps<{
    borrowers: readonly LoanPlace[];
    borrowerLabel: string;
    returnFocus: HTMLElement | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ done: [run: BulkRun, borrower: LoanPlace | null] }>();

const id = useId();
const code = ref('');
const candidates = ref<LendCandidate[]>([]);
const codeProblem = ref<string | null>(null);
const codeError = ref<ApiError | null>(null);
const looking = ref(false);
const borrowerId = ref('');
const contact = ref('');
const due = ref('');
const comment = ref('');
const touched = ref(false);
const progress = ref<{ done: number; total: number } | null>(null);

watch(open, (value) => {
    if (value) {
        code.value = '';
        candidates.value = [];
        codeProblem.value = null;
        codeError.value = null;
        borrowerId.value = '';
        contact.value = '';
        due.value = '';
        comment.value = '';
        touched.value = false;
        progress.value = null;
    }
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

const options = computed((): SelectOption[] =>
    props.borrowers.map((place) => ({ value: String(place.id), label: place.name })),
);
const dueProblem = computed(() => dateProblem(due.value));
const problem = computed(
    () => lendProblem(candidates.value, borrowerId.value, contact.value) ?? dueProblem.value,
);

const unsaved = computed(
    () =>
        busy.value ||
        (open.value &&
            (candidates.value.length > 0 ||
                code.value.trim() !== '' ||
                contact.value.trim() !== '' ||
                due.value !== '' ||
                comment.value.trim() !== '')),
);
useUnsavedInput(() => unsaved.value);

async function addCode(): Promise<void> {
    const text = code.value.trim();
    if (text === '' || looking.value || busy.value) {
        return;
    }
    looking.value = true;
    codeProblem.value = null;
    codeError.value = null;
    const found = await deviceStore.findByCode(text);
    if (!found.ok) {
        looking.value = false;
        codeError.value = found.error;
        return;
    }
    if (found.data === null) {
        looking.value = false;
        codeProblem.value = 'Keine Ausrüstung mit diesem Code gefunden.';
        return;
    }
    // Die Version muss stimmen: aktuellen Stand holen.
    const current = await deviceStore.fetchCurrent(found.data.id);
    looking.value = false;
    if (!current.ok) {
        codeError.value = current.error;
        return;
    }
    const device = current.data;
    if (device.status === 'retired') {
        codeProblem.value = `${device.identifier} ist ausgesondert und lässt sich nicht verleihen.`;
    } else if (candidates.value.some((entry) => entry.id === device.id)) {
        codeProblem.value = `${device.identifier} steht schon in der Liste.`;
    } else {
        candidates.value = [
            ...candidates.value,
            {
                id: device.id,
                version: device.version,
                identifier: device.identifier,
                typeName: device.deviceType.name,
            },
        ];
        code.value = '';
    }
}

function remove(entry: LendCandidate): void {
    candidates.value = candidates.value.filter((candidate) => candidate.id !== entry.id);
}

async function submit(): Promise<void> {
    touched.value = true;
    if (problem.value !== null || busy.value) {
        return;
    }
    const dueDate = parseDateInput(due.value);
    if (dueDate === undefined) {
        return;
    }
    const borrower = props.borrowers.find((place) => String(place.id) === borrowerId.value) ?? null;
    if (borrower === null) {
        return;
    }
    progress.value = { done: 0, total: candidates.value.length };
    const contactText = contact.value.trim().replace(/\s+/g, ' ');
    const commentText = comment.value.trim().replace(/\s+/g, ' ');
    const run = await lendEquipment(
        candidates.value,
        {
            borrowerLocationId: borrower.id,
            ...(contactText !== '' && { contact: contactText }),
            ...(dueDate !== null && { dueBackOn: dueDate }),
            ...(commentText !== '' && { comment: commentText }),
        },
        (done, total) => {
            progress.value = { done, total };
        },
    );
    progress.value = null;
    open.value = false;
    emit('done', run, borrower);
}
</script>

<template>
    <FormDialog
        v-model:open="dialogOpen"
        :unsaved="unsaved"
        title="Verleihen"
        description="Die Ausrüstung wird auf den Leihnehmer gebucht; jede erhält einen eigenen Eintrag im Verlauf."
        submit-label="Verleihen"
        :busy="busy"
        :can-submit="!touched || problem === null"
        :return-focus="returnFocus"
        @submit="submit"
    >
        <div class="field">
            <label class="field-label" :for="`${id}-code`">Nummer oder Scan-Code</label>
            <div class="add-row">
                <input
                    :id="`${id}-code`"
                    v-model="code"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :disabled="busy"
                    :aria-describedby="codeProblem !== null ? `${id}-code-error` : undefined"
                    :aria-invalid="codeProblem !== null"
                    data-testid="lend-code"
                    @keydown.enter.prevent="addCode"
                />
                <button
                    class="btn"
                    type="button"
                    :disabled="busy || looking || code.trim() === ''"
                    data-testid="lend-add"
                    @click="addCode"
                >
                    Hinzufügen
                </button>
            </div>
            <p v-if="codeProblem !== null" :id="`${id}-code-error`" class="field-error">
                {{ codeProblem }}
            </p>
            <ErrorNotice v-if="codeError !== null" :error="codeError" />
        </div>

        <ul v-if="candidates.length > 0" class="candidates" data-testid="lend-list">
            <li v-for="entry in candidates" :key="entry.id">
                <span class="identifier">{{ entry.identifier }}</span>
                <span class="type">{{ entry.typeName }}</span>
                <button
                    class="btn btn-small"
                    type="button"
                    :disabled="busy"
                    :aria-label="`${entry.identifier} entfernen`"
                    @click="remove(entry)"
                >
                    Entfernen
                </button>
            </li>
        </ul>
        <p v-else class="field-hint">Noch keine Ausrüstung hinzugefügt.</p>

        <div class="field">
            <AppSelect
                v-model="borrowerId"
                :label="borrowerLabel"
                :options="options"
                placeholder="Bitte wählen"
            />
        </div>
        <label class="field">
            <span class="field-label">Ansprechpartner (optional)</span>
            <input
                v-model="contact"
                class="input"
                type="text"
                autocomplete="off"
                maxlength="100"
                :disabled="busy"
            />
        </label>
        <label class="field">
            <span class="field-label">Rückgabe bis (optional)</span>
            <input
                v-model="due"
                class="input"
                type="date"
                :min="DATE_MIN"
                :max="DATE_MAX"
                :disabled="busy"
                :aria-invalid="touched && dueProblem !== null"
            />
            <p v-if="touched && dueProblem !== null" class="field-error">{{ dueProblem }}</p>
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
        <p
            v-if="touched && problem !== null && dueProblem === null"
            class="field-error"
            role="alert"
        >
            {{ problem }}
        </p>
        <p v-if="progress !== null" class="progress" role="status" data-testid="bulk-progress">
            {{ progress.done }} von {{ progress.total }} gesendet …
        </p>
    </FormDialog>
</template>

<style scoped>
.add-row {
    display: flex;
    gap: 8px;
}

.add-row .input {
    flex: 1 1 auto;
}

.candidates {
    display: flex;
    flex-direction: column;
    margin: 0;
    padding: 0;
    list-style: none;
}

.candidates li {
    display: flex;
    align-items: center;
    gap: 6px 12px;
    min-height: var(--touch-min);
    border-top: 1px solid var(--border-subtle);
}

.identifier {
    min-width: 5em;
    font-weight: var(--font-weight-bold);
}

.type {
    flex: 1 1 auto;
}

.field-hint {
    margin: 0;
    font-size: var(--font-size-sm);
    color: var(--muted);
}

.progress {
    margin: 0;
    font-weight: var(--font-weight-semibold);
}
</style>
